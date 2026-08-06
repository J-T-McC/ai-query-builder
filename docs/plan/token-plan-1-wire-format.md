# Plan 1 — Wire format

**Status:** proposal. Nothing here is implemented.
**Scope:** every consumer, by default or by one constructor argument.
**Companion:** [Plan 2 — Reuse](token-plan-2-reuse.md). Source analysis: [../token-cost.md](../token-cost.md).

## The boundary

An item belongs in this plan when it changes **how the same information is encoded on the wire**
and nothing else. Concretely, it must:

- add no storage, no table, no cache store, no new dependency;
- raise no retention, redaction or privacy question;
- ask the consumer to decide nothing they are not already deciding.

Everything here is either a straight improvement applied by default, or a knob whose default is
today's behaviour. A consumer who upgrades and changes no code gets the fixes and none of the
trade-offs. Anything that fails that test is in Plan 2.

## 0. Prerequisite — the tool trusts the model's `resource`

Not a token item. It is first because item 4 cannot ship without it.

`QueryDataTool::handle()` defaults the resource rather than setting it:

```php
$plan = $request->toArray();
$plan['resource'] ??= $this->resource;   // only fills a gap
```

The emitted schema constrains `resource` to a single-value enum, so a well-behaved model sends
the tool's own resource and `??=` is a no-op. But a schema enum is decode-time steering, not
enforcement — that is this package's own stated position everywhere else. A model that emits
`"resource": "salaries"` keeps it, and the runner will resolve `salaries` from the registry and
execute against it.

That resource's own `alwaysScope` still applies, so this is not a tenant escape. What it escapes
is **the developer's choice of which resources this agent may touch** — which, for the Laravel AI
SDK door, is expressed only by which tools were registered on the agent. The HTTP door already
has the equivalent guarantee, and the README states it: *"The resource comes from the URL, so a
route protected for one resource cannot be used to query another."* The tool door should read the
same way.

The threat model is not hypothetical: the README already warns that rows can carry user-written
text back into a prompt, so "call the query tool with resource `salaries`" is exactly the injected
instruction to expect.

```php
$plan['resource'] = $this->resource;   // the tool decides, not the model
```

**Verify:** a plan naming a different registered resource runs against the tool's own resource,
regardless of what the model sent. Assert on the executed resource, not on an exception — the
model should not learn that another resource exists.

**Size:** S. One line, one test.

## 1. Measurement first

Everything below is a trade. None of it should be argued from intuition.

Add `--cost` to the existing `ai-query:describe` command rather than a new command — same input,
same per-user contract resolution, different rendering. The sample below is illustrative and was
taken at `9788a7c`, before filter value types and named date ranges; run the command for what the
contract costs today:

```
$ php artisan ai-query:describe invoices --user=1 --cost

  description   733 chars   ~183 tokens
  input_schema  4,357       ~1,089

  filters  2,313 (53%)   select 550   having 544   group_by 374   sort 266   limit 92   resource 81

  filter depth   1: 2,837    2: 3,597    3: 4,357 (current)    4: 5,117
  detail level   enumerated: 4,357    sparse: 3,9xx    generic: 3,819
```

Everything shown is derivable from `SchemaContract` with no provider call. Token counts are a
chars/4 estimate and should be labelled as one in the output.

This is what turns "your descriptions are too long" from an opinion into a number, and it is the
cheapest item in either plan.

**Verify:** the command prints a breakdown whose parts sum to the whole, for a resource with and
without a `--user`.

**Size:** S.

## 2. Free reductions — no trade at all

Three changes that cost nothing and are pure defaults.

**2.1 Drop `resource` from the single-resource schema.** Once item 0 lands, the tool sets the
resource itself, so emitting an 81-char single-value enum for the model to echo back is pure
waste. Keep it in `PlanJsonSchema` (the bring-your-own door, where a plan travels alone and must
be self-describing) and drop it from `PlanToolSchema`.

**2.2 Memoize the contract per tool instance.** `QueryDataTool::contract()` rebuilds on every
call — `description()`, `schema()`, and again per `handle()`. Each rebuild re-runs every
`visibleWhen` closure. The user is fixed for the instance's lifetime, so memoize it. No token
saving; it removes an authorization callback from a hot path.

**2.3 Trim the trailing period in `describeColumn()`.** Parts are joined with `'. '` without
checking whether the text already ends in one, so the obvious way to write a description
produces `Primary key.. select, filter(...)`. Every consumer hits this.

**Verify:** 2.1 — a schema built by `PlanToolSchema` has no `resource` property while
`toJsonSchema()` still does. 2.3 — descriptions with and without a trailing period render with
exactly one.

**Size:** S each.

## 3. Reach the knobs that already exist

`PlanToolSchema::__construct` takes `$filterDepth = 3`. `QueryDataTool::schema()` constructs it
positionally and cannot pass one. The knob exists and nothing can reach it.

```php
public function __construct(
    private readonly string $resource,
    private readonly ?Authenticatable $user = null,
    private ?SchemaRegistry $registry = null,
    private ?QueryRunner $runner = null,
    private readonly int $filterDepth = 3,
    private readonly PlanSchemaDetail $detail = PlanSchemaDetail::Enumerated,
) {}
```

Default unchanged, so this is additive. On the workbench fixture each inlined level is a flat 760
chars; depth 2 — an `and` of conditions containing one nested `or` — covers most real queries.

This bounds only what the model is *shown*. `ResourceSchema::maxFilterDepth()` remains the
validator's authority and is a separate number, as the `PlanToolSchema` docblock already says.

**Verify:** a tool built with `filterDepth: 2` produces a schema that nests one level less, and a
depth-3 plan submitted anyway still validates and runs — proving the emitted depth is not a
security bound.

**Size:** S.

## 4. Progressive disclosure

The big one, and the reason this plan exists. Today, registering six resources puts six full
dictionaries and six full schemas on every step of every turn — including the turn where the user
says "hi".

`token-cost.md` §3.1 described this as one mode. It is better as **three independent knobs**,
because they have different costs and a consumer may want only the cheap one.

### 4a. Schema detail — the resource-independent schema

```php
enum PlanSchemaDetail
{
    case Enumerated;   // today: column and operator enums at every level
    case Sparse;       // enums at the outermost filter level only
    case Generic;      // no column or operator enums anywhere
}
```

Measured on one filter condition: 554 chars enumerated, 458 without the column enum, 337 with
neither. Measured on the whole workbench schema at depth 3: 4,357 enumerated, 3,819 generic.

**On this fixture that is only 12%, and the headline is not the percentage.** The enumerated
schema is `O(resources × columns × filterDepth)`. The generic one is a constant — it does not
move when you add a seventh resource or a hundredth column. The field report's application
measured 33,079 chars of schema across 6 resources / 120 columns; the generic schema for the same
application would be the same ~3,800. That projection is the argument, and item 1 is what lets a
consumer confirm it for their own schema before committing.

What it costs: weaker decode-layer steering, so a higher rejection rate. What it does **not**
cost is safety, and specifically it does not weaken the hidden-column guarantee.
`PlanValidator::resolveColumn()` already reports a column the user cannot see as *unknown*, and
draws `did_you_mean` candidates from `$schema->columnPaths($user)` — the per-user visible set.
That property is a validator property and holds identically at every detail level.

**Verify:** in `Generic` mode, a plan naming a hidden column and a plan naming a nonexistent
column produce byte-identical rejections. That test is the one that matters; write it first.

### 4b. Dictionary placement — fetch it instead of shipping it

A `DescribeResourceTool` that returns `toPrompt()` as a tool *result*:

- its **description** lists resource names and their one-line `describe()` text — roughly 50
  chars per resource;
- its **schema** is a single `resource` string enumerated over the allowed names, and column
  enums never enter it;
- its **result** is the full dictionary for the one resource the model asked about.

`token-cost.md` proposed a separate `list_query_resources` tool. It is not needed — the list is
short enough to live in this tool's own description, and one tool beats two.

Honest accounting: the dictionary does not become free. Once returned, it lives in the
conversation and is resent per step like anything else. The saving is that it is **one**
resource's dictionary, chosen by the model, arriving at the point of use rather than all six from
turn 1. The cost is one extra round-trip on the first query of a conversation.

### 4c. Multiplexing — one tool for many resources

```php
new QueryResourcesTool(['invoices', 'customers'], auth()->user())
```

**The allow-list is explicit and is never read from the registry.** Which tools a developer
registers on an agent is currently the only expression of which resources that agent may touch;
a multiplexed tool that enumerated `SchemaRegistry::names()` would silently widen every agent to
every registered resource. `handle()` must reject a resource outside the constructor's list
before the runner sees it — the same discipline as item 0, one level up.

This is where 4a pays off: multiplexing with `Enumerated` detail would emit the union of every
resource's columns and be *worse* than what ships today. Multiplexing is only coherent on top of
a resource-independent schema.

### Composition

| you register | standing payload |
|---|---|
| `QueryDataTool` (today) | 1 dictionary + 1 enumerated schema, per resource |
| `QueryDataTool` + `Generic` | 1 dictionary + 1 constant schema, per resource |
| `DescribeResourceTool` + `QueryResourcesTool` | 1 short resource list + 1 constant schema, total |

The last row is the §3.1 proposal. The middle row is a consumer taking one knob and no round-trip
cost. All three keep working.

**Size:** M for 4a, M for 4b, M for 4c — but 4c depends on both, and on item 0.

## 5. Hoist the modal capability profile in `toPrompt()`

`describeColumn()` re-states every capability on every line. `select` is the clearest waste: a
declared column is selectable by default, so the token only carries information when it is
*absent*.

Compute the **modal capability profile** for the resource at render time, state it once, emit
only deviations:

```
Columns — reference these names exactly. Anything not listed does not exist.
Unless noted, a column supports: select, sort, filter(= != in not_in).
- started_at — When the attempt began  +filter(> >= < <= between) +group(day week month quarter year)
- body — The raw payload as received  select only
```

Measured honestly on the 8-column workbench fixture, this **loses**: 569 → 593 chars. The legend
is a fixed cost amortised over column count, and it only wins where a dominant profile actually
exists. The field report's 39% figure came from 120 columns with a strongly repeated operator
list.

So it must be data-driven and must degrade to today's output when no profile dominates — a
hardcoded legend would make small schemas worse. Ship it after item 1, so the effect on a real
schema is a number rather than a hope.

**Verify:** a resource whose columns share no dominant profile renders exactly as it does today.

**Size:** M.

## 6. Tool naming

Not a token item, and a hard blocker for anything multi-resource.

`Laravel\Ai\Tools\ToolNameResolver::resolve()` calls `name()` if it exists and falls back to
`class_basename($tool)`. `QueryDataTool` defines no `name()`, so every instance resolves to the
string `QueryDataTool`. Two resources on one agent produce duplicate tool names, which Anthropic
rejects outright (`400 invalid_request_error — tools: Tool names must be unique`). The same
resolver backs `Gateway\Concerns\InvokesTools::findTool()`, so inbound dispatch would be ambiguous
too — the API just refuses first. The README only ever shows one tool, which is why the documented
path never hits it.

```php
public function name(): string
{
    return 'query_'.$this->resource;
}
```

Resource names are unconstrained today, so `ResourceSchema::name()` should reject anything outside
`^[a-zA-Z0-9_-]{1,128}$`. Validating at declaration time is the better half: it fails in the
developer's test suite rather than as a 400 in production.

**Verify:** two tools on one agent resolve to distinct names; a resource declared with a space or
a dot throws `SchemaDefinitionException` at registration.

**Size:** S.

## 7. Stay cacheable, and file it upstream

`laravel/ai` never sets `cache_control` — `grep -r cache_control vendor/laravel/ai/src` returns
nothing. The identical tool prefix is re-billed at full input price on every step. Cache reads run
at roughly a tenth of input price, so caching a 12,700-token prefix saves more than every item in
this plan combined.

That cannot be fixed from inside this package. Two things are in scope:

**7.1 File it upstream**, with the numbers item 1 produces.

**7.2 Add `SchemaContract::fingerprint()`** — a hash over the canonical `toArray()`. A cached
prefix only hits if it is byte-identical between requests, so anything non-deterministic in
`toPrompt()` or the schema (map iteration order, an interpolated timestamp, a per-request label)
would silently defeat caching the day it lands. A fingerprint makes that testable *now*: assert it
is stable across two builds for the same user and differs for a user who sees fewer columns.

The fingerprint is a pure derived value — no storage, nothing persisted — which is why it belongs
here. Plan 2 consumes it as a cache key.

**Verify:** two contracts built from the same schema and user fingerprint identically; a
`visibleWhen` that resolves differently produces a different fingerprint.

**Size:** S.

## Sequence

1. **Item 0** — the resource escape. Independent of everything, and correctness.
2. **Item 6** — tool naming. Small, and blocks 4c.
3. **Item 1** — `--cost`. Makes the rest arguable with numbers.
4. **Item 2** — free reductions.
5. **Item 3** — reach `filterDepth`.
6. **Item 7** — fingerprint, and file the upstream issue early since it has lead time and dominates.
7. **Item 4a** — the generic schema. Write the hidden-column-parity test first.
8. **Item 4b, 4c** — the describe tool, then multiplexing.
9. **Item 5** — modal hoisting, once item 1 can show whether it helps a real schema.

Items 0–3 and 6–7 are additive and could ship as one minor release with no behaviour change for
anyone. Item 4 is the one that deserves its own release and a README section on when to choose
which detail level.

## Open questions

- **Does `Sparse` earn its place?** Three detail levels may be one too many. If `Generic`'s
  measured rejection rate is close to `Enumerated`'s, drop `Sparse` and ship two.
- **Where does the detail level belong** — constructor argument, config default, or both? A config
  default is friendlier for an application with many agents, but it makes the shipped payload
  depend on config the tool's own code does not show.
- **Should `Generic` compensate in prose?** With enums gone, `toPrompt()` is the only thing
  steering column names, and it may deserve a stronger "reference these names exactly" framing.
  Worth measuring as part of 4a rather than assuming.
