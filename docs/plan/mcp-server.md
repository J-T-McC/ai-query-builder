# Plan — MCP Server

**Status:** accepted — implemented alongside this document.
**Scope:** additive. No breaking changes; no new required dependencies.
**Companion:** [architecture.md](architecture.md) §8 — this is Door 4.

## Why

The package's core — schema contracts, plan validation, compilation, guarded execution — is
transport-agnostic. Today it has three doors: the Laravel AI SDK tools, the HTTP endpoint, and
`QueryRunner` directly. None of them help someone whose AI runs *outside* the application:
Claude Desktop, Claude Code, Cursor, ChatGPT, or any other MCP client.

An MCP server is that fourth door. It is not a replacement for the `laravel/ai` tools — those
serve agents the application hosts; MCP serves agents the *user* hosts. Both fronts sit on the
same validation gate, so "why not MCP" and "why not laravel/ai" have the same answer: both,
because the expensive part (the safe query core) is shared and each door is thin.

**Positioning, stated plainly so it cannot drift:** the package's core promise — a developer
builds a chat agent or reporting tool *into their own product* — is served by the `laravel/ai`
door, and MCP does not change that. MCP is reach: it serves people pointing a third-party
client (Claude Desktop, Claude Code, Cursor) at the application from outside it — the developer
during development, an internal team that wants data access without built UI, a power user who
lives in their own AI client. An application's own agent should **not** consume its own MCP
server: when agent and data share one process, MCP is an HTTP loop through your own front door
to reach tool classes already in memory. The docs and README section must say this, so nobody
architects the indirection. The two doors are the same capability for two audiences — in-product
(laravel/ai) and out-of-product (MCP).

Laravel has an official package for exactly this: [`laravel/mcp`](https://laravel.com/docs/mcp).
Its tool contract is nearly identical to the `laravel/ai` one we already implement — same
`Illuminate\JsonSchema` builder in `schema(JsonSchema $schema)`, same `handle(Request $request)`
shape — so the existing `PlanToolSchema`, `SchemaContract`, and `QueryRunner` plug in unchanged.

## Runtime context: what an MCP tool can see

This was the open question, and the answer is yes on both counts.

**Request-level details.** `laravel/mcp` has two transports:

| Transport | Registered via | Runs as | `$request->user()` |
| --- | --- | --- | --- |
| Web | `Mcp::web('/mcp/query', Server::class)` in `routes/ai.php` | A normal HTTP POST route | The authenticated user, via whatever middleware the host applies (`auth:sanctum`, Passport's `auth:api`, or custom) |
| Local | `Mcp::local('query', Server::class)` | An Artisan process the MCP client spawns | `null` unless the host resolves one |

The web transport is the important one: it goes through the full HTTP kernel, so middleware,
guards, rate limiting, and `$request->user()` all behave exactly as on the existing
`QueryController` route. Authentication is the host's middleware choice — the package never
picks a guard, same stance as `routes/ai-query-builder.php` today.

**Who authenticates, and who pays.** The MCP client never has its own identity — it carries the
*user's* credentials: a Sanctum token the user creates in the application and pastes into their
client config, or an OAuth 2.1 grant (`Mcp::oauthRoutes()` + Passport) where the user approves
the client in a browser against the application's own login. Either way every tool call resolves
to a real user. And the inference cost inverts relative to the `laravel/ai` door: the model runs
in the user's client against the user's subscription or API key, while the application serves
only ordinary HTTP requests and database queries. A host can offer "query your data with your
own AI" at zero AI spend. The package's token-hygiene design (generic plan schemas, dictionaries
on demand) still applies — it now protects the user's context window rather than the
application's bill.

**App details.** Tools are resolved from the container (constructor and `handle()` method
injection both work), so config, the `SchemaRegistry`, and the `QueryRunner` are all available
at runtime. Nothing about MCP is a separate process from the app on the web transport.

**Scoping consequence.** `$request->user()` flows into the exact seam the package already has:

```php
SchemaContract::for($schema, $request->user());   // visibleWhen() column filtering
$runner->as($request->user())->run($plan);        // authorization scopes at execution
```

A user who can't see a column doesn't get it in the dictionary, the schema, or the results —
identical to the `laravel/ai` tools. On a local server the user is `null`, which the schema
layer already defines (guest visibility); the docs should say plainly that local servers are
for trusted single-developer use.

## What we ship

Three classes under a new `src/Mcp` namespace, mirroring the existing `src/Ai` trio, plus a
ready-made server:

### 1. `Mcp\Tools\QueryResourcesTool`

The MCP counterpart of `Ai\QueryResourcesTool`: one tool, several resources, generic plan schema
(`PlanSchemaDetail::Generic`) so the payload doesn't grow with any contract. `handle()` pins the
resource to the configured list, runs the plan as `$request->user()`, and returns results as
structured content (`Response::structured(...)`) so MCP clients that parse output schemas can.
An `InvalidQueryPlanException` returns the same corrective JSON the AI tools return — as a
normal (non-error) response, so the model can retry with fixed paths.

### 2. `Mcp\Tools\DescribeResourceTool`

The dictionary-on-demand counterpart of `Ai\DescribeResourceTool`. Same trade: the standing tool
description carries only resource names and one-line descriptions; the model fetches the full
dictionary for the resource it actually wants. Annotated `#[IsReadOnly]` and `#[IsIdempotent]`.

### 3. `Mcp\QueryServer`

A `Laravel\Mcp\Server` subclass registering both tools, so the minimal host setup is two steps:

```php
// config/ai-query-builder.php
'mcp' => [
    'resources' => ['invoices'],   // exposure is opt-in, default []
],

// routes/ai.php
Mcp::web('/mcp/query', \JTMcC\AiQueryBuilder\Mcp\QueryServer::class)
    ->middleware('auth:sanctum');
```

### Where the resource list comes from

With `laravel/ai`, exposure is expressed by *which agent* you build — each agent constructs its
tools with an explicit list (`new QueryDataTool('invoices', $user)`). An MCP endpoint has no
agent, but it has an exact structural equivalent: the **server class**. `laravel/mcp`'s
`Server::$tools` accepts tool *instances* as well as class-strings (verified against the
`Server` source: `array<int, Tool|class-string<Tool>>`, plus a `boot()` lifecycle hook), so the
package's base `QueryServer` can construct its tools with the exposure injected — and a host
declares a group's exposure as one property on one small class.

**Layer 1 — per group: one server class per audience.** The analog of one `laravel/ai` agent
per audience. `QueryServer` builds its tool instances in `boot()` from a single `$exposes`
property; a subclass overrides only that:

```php
// routes/ai.php
Mcp::web('/mcp/admin', AdminQueryServer::class)->middleware(['auth:sanctum', 'can:admin']);
Mcp::web('/mcp/tenant', TenantQueryServer::class)->middleware('auth:sanctum');

// app/Mcp/AdminQueryServer.php — a static subset of the registered resources
class AdminQueryServer extends QueryServer
{
    protected array|string $exposes = ['invoices', 'customers', 'audit_logs'];
}

// app/Mcp/TenantQueryServer.php — resolved per user (Layer 2)
class TenantQueryServer extends QueryServer
{
    protected array|string $exposes = TenantResources::class;
}
```

**Layer 2 — per user, within a group:** `$exposes` (and the config key below) accepts a
class-string implementing one contract, resolved from the container and evaluated per request:

```php
interface ResolvesExposedResources
{
    /** @return list<string> */
    public function resources(?Authenticatable $user): array;
}
```

The list — not the resolver — reaches the tools lazily: tools hold the `array|string` value and
normalize it at request time with `$request->user()`. This works because nothing about an MCP
catalogue is static: every request, including the `tools/list` call that populates the client's
tool picker, passes through the server's auth middleware. Two users connected to the same
endpoint see different catalogues, and a user whose resolved list is empty sees no query tools
at all (`shouldRegister()` returns `false`).

**Layer 3 — the default: `ai-query-builder.mcp.resources`, default `[]`.** The shipped
`QueryServer` is registrable directly; when `$exposes` is untouched it reads this key, which
takes the same `array|string` shapes. The empty default means installing the package never
silently opens data to MCP clients — secure by default, same as the HTTP route's
`routes.enabled` — and the key preserves the registration-vs-exposure distinction the README
establishes: `resources` is the global allowlist; MCP exposure is per server, opt-in.

Implementation note to verify: `DescribeResourceTool`'s description enumerates the exposed
resources, which under a resolver is per-user. `laravel/mcp` documents descriptions via the
`#[Description]` attribute; confirm the base `Tool` class allows overriding the description via
method for dynamic content (likely, since tool instances are supported), and if not, move the
catalogue into the tool's result (call with no argument → list resources) and keep the
attribute description static.

Per-resource tools with enumerated schemas (the `query_invoices` pattern) are deliberately out of
scope for v1: MCP clients re-send tool schemas per session rather than per step, so the token
economics that motivated `QueryDataTool`'s enumerated-schema-per-resource design matter less
here, and the generic-schema pair covers the capability. If demand shows up, an abstract
per-resource tool is a straightforward follow-up.

### Phase 2 (optional, not in the first PR)

Expose each dictionary as an MCP **resource** via a URI template
(`ai-query://resources/{resource}`). Some clients surface MCP resources as attachable context,
which fits a data dictionary well. It duplicates `DescribeResourceTool`'s content, so it waits
until a client-driven reason exists.

## Dependency and compatibility

Mirrors the `laravel/ai` arrangement exactly:

- `laravel/mcp` goes in **`require-dev`** (to test against) and **`suggest`** (for consumers).
  The `src/Mcp` classes extend `Laravel\Mcp\Server\Tool` / `Server`, and are only loaded when a
  host references them — the package keeps working with no MCP layer at all, exactly as it works
  today with no AI layer.
- No service-provider changes. MCP servers are registered by the host in `routes/ai.php`; the
  package registers nothing automatically. The only package change outside `src/Mcp` is the new
  `mcp.resources` config key.
- Compatibility check before implementation: confirm the `laravel/mcp` release lane that supports
  `illuminate/* ^13` and PHP 8.3, and that `prefer-lowest` on the CI matrix resolves a working
  version (run through the `package-compatibility` skill).

## Breaking changes

**None.**

- No existing class, method, config key, route, or publish tag changes.
- New config key `mcp.resources` defaults to `[]` — merged config means existing published
  configs keep working without republishing.
- Composer changes are `require-dev` + `suggest` only; consumer installs are unaffected.
- Release as a **minor** version (v0.4.0).

## Testing

`laravel/mcp` ships first-class Pest helpers, so tests stay on observable behavior through the
public surface, per the house rules:

- `QueryServer::tool(QueryResourcesTool::class, [...])->assertOk()` feature tests: a valid plan
  returns rows; an invalid plan returns the corrective error shape; a resource outside
  `mcp.resources` is refused.
- `QueryServer::actingAs($user)->tool(...)` scoping tests: `visibleWhen` columns absent from
  both dictionary and results for the wrong user — the same fixtures the `Ai` tool tests use.
- `DescribeResourceTool` returns the contract prompt; unknown resource refused.
- Workbench: register the server in the workbench app so `php artisan mcp:inspector` works for
  manual verification against the existing invoice fixtures.

## Implementation order

1. Compatibility check + composer changes (`require-dev`, `suggest`).
2. Config key + `ResolvesExposedResources` contract + `Mcp\Tools\DescribeResourceTool` + tests
   (static list and resolver paths both covered).
3. `Mcp\Tools\QueryResourcesTool` + tests.
4. `Mcp\QueryServer` + workbench registration + inspector smoke test.
5. README section ("Exposing resources over MCP") with the Sanctum web-server example and the
   local-server caveat; regenerate the Boost skill (`package-generate-skill`).

Estimated surface: ~4 new classes, ~1 config key, README section, no touches to existing code
paths beyond `composer.json` and config.

## Risks, and the case against

An honest accounting, because "add MCP" is fashionable and fashion is not a reason.

- **`laravel/mcp` is young.** It is an official Laravel package with first-class docs and test
  helpers, but it is early-lifecycle and its API may still move. Mitigation: it never enters
  `require`; the door is thin (four classes); breakage lands in our test matrix, not in
  consumers who haven't opted in.
- **The MCP client ecosystem skews developer-tool today.** Connecting Claude Code, Claude
  Desktop, or Cursor to your own application works well now and is the strongest immediate use
  case. End users connecting a chat client to a multi-tenant SaaS over OAuth is the newest part
  of the ecosystem — real and growing, but less proven. The per-user resolver is built for that
  second case; if it lags, the per-server layer still carries the developer-tool case fine.
- **Exposure narrowing is not the security boundary — and must never become it.** Schema
  authorization (`visibleWhen`, `alwaysScope`, the validation gate) is the boundary, and it
  runs regardless of which door a plan arrives through. A misconfigured `$exposes` list
  advertises a catalogue entry it shouldn't; it does not leak a row or a column the user's
  schema scoping would refuse. Exposure is UX and token hygiene. This property is what makes
  the whole MCP door low-risk to add, and the docs must state it so nobody relies on exposure
  lists for security.
- **A fourth door is a standing maintenance cost.** Tests, README, the Boost skill, and the
  compatibility matrix all grow. The mirror-the-`src/Ai`-trio design keeps that cost bounded,
  but it is not zero.
- **What "why not MCP" actually gets wrong.** MCP is a transport; this package is a safety
  layer. An MCP server that exposes raw SQL or a naive query endpoint is exactly the thing this
  package exists to prevent. The honest pitch is not "we have MCP too" — it is "this is what
  makes a data-query MCP server safe to stand up."

**De-risking step:** before committing to the full build, step 4's workbench registration can be
pulled forward as a spike — stand the server up in the workbench, connect the MCP Inspector and
a real client (Claude Code) to it, and query the invoice fixtures. A working end-to-end session
against real fixtures is a better basis for judging the design than this document.

## Open questions

1. **Duplication between `src/Ai` and `src/Mcp`.** The `handle()` bodies (pin resource → run as
   user → JSON error on rejection) will be near-identical. The house rule prefers explicit code
   over premature abstraction; the proposal is to accept the duplication in v1 and only extract
   a shared support class if a third door needs it.
2. **Error surface.** Plan rejections return as normal content (so the model self-corrects), and
   `Response::error()` is reserved for genuinely unrecoverable states (unknown resource,
   unauthorized). Confirm this matches how major MCP clients feed `isError` results back to the
   model.
3. **Resolver caching.** A resolver runs on every MCP request, including `tools/list`. That is
   the same per-request cost profile as the middleware stack itself, so no caching ships in v1 —
   a host with an expensive lookup can memoize inside their own resolver.