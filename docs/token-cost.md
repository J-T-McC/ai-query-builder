# Reducing token cost

**Status:** analysis. Nothing here is implemented.
**Plans:** [Plan 1 — Wire format](plan/token-plan-1-wire-format.md) (applies to everyone),
[Plan 2 — Reuse](plan/token-plan-2-reuse.md) (opt-in, requires a consumer decision).

Every tool an agent carries is resent on **every step** of the agent loop. A `QueryDataTool` puts
two things on the wire — `description()` (the data dictionary) and `input_schema` (the plan
schema) — and both are billed again each time the model thinks. A resource that is expensive to
describe is expensive on turns that never query it.

This document measures where that cost comes from, states the principle that makes trading it
away safe, and proposes changes in order of leverage.

## 1. Where the tokens go

Measured against the workbench `invoices` fixture — 8 visible columns, 6 filterable, one
relation two levels deep — serialized exactly as `laravel/ai` 0.10.2 sends it to Anthropic
(`Gateway\Anthropic\Concerns\MapsTools::mapTool`):

| Part | chars |
|---|---:|
| `description()` — `SchemaContract::toPrompt()` | 733 |
| `input_schema` — `PlanToolSchema` at the default depth 3 | 4,357 |

The schema breaks down as:

| plan property | chars | share |
|---|---:|---:|
| **`filters`** | **2,313** | **53%** |
| `select` | 550 | 13% |
| `having` | 544 | 12% |
| `group_by` | 374 | 9% |
| `sort` | 266 | 6% |
| `limit` | 92 | 2% |
| `resource` | 81 | 2% |

Filter depth is a straight-line multiplier. On this fixture each inlined level costs exactly
**760 chars**:

| `filterDepth` | schema chars |
|---|---:|
| 1 | 2,837 |
| 2 | 3,597 |
| **3 (default)** | **4,357** |
| 4 | 5,117 |

And within one filter condition, the enums are most of it:

| condition object | chars |
|---|---:|
| column enum + operator enum (current) | 554 |
| operator enum only | 458 |
| neither | 337 |

The important property is the **shape of the growth**, not these absolute numbers. The emitted
schema is `O(resources × columns × filterDepth)`. A field report from an application registering
6 resources / 120 columns measured 50,780 chars (~12,700 tokens) of standing tool payload, split
65% schema / 35% prose — consistent with the growth above.

## 2. The principle that makes these trades safe

**The JSON Schema enums are an accuracy optimization, not a security control.**

`PlanValidator` is the security boundary, and it is the only one. It re-derives every permitted
column path, operator, aggregate and bucket from the same per-user contract, and it fails closed
on anything it does not recognise. A plan that arrives with an invented column is rejected
whether or not the schema also happened to forbid it.

So every reduction below costs at most **accuracy** — a higher chance of one correction
round-trip — and never **safety**. That is a real cost and it is measurable: `QueryPlanRejected`
already carries error codes, so rejection rate before and after any change here is observable
rather than guessed. It is just not a security cost, and it should not be argued about as one.

Two things follow:

- A correction round-trip is a *one-time* cost on the turns that need it. The schema is a
  *standing* cost on every step of every turn. Trading the second for the first is usually right,
  and gets more right the larger the schema is.
- `QueryDataTool::handle()` already returns rejections to the model with `did_you_mean`
  suggestions rather than throwing. The self-correction path is built and exercised.

## 3. Proposals, by leverage

### 3.1 Progressive disclosure — stop shipping every dictionary up front

**The single biggest lever.** Today, registering six resources means six full dictionaries and
six full schemas ride on every step, including the turn where the user says "hi". Split the
surface into three tools:

| tool | payload | cost |
|---|---|---|
| `list_query_resources` | resource names + one-line descriptions | ~50 chars per resource |
| `describe_query_resource(resource)` | that resource's `toPrompt()`, as a **tool result** | paid once, for the one resource used |
| `query_data(resource, plan)` | one enum-free plan schema | fixed, ~3,100 chars |

The enum-free schema is the point: it does not grow with resources or columns. Measured on the
same fixture, a fully generic plan schema is **3,143 chars at depth 2 and 3,819 at depth 3, and
those numbers do not move when you add a seventh resource or a hundredth column.** For a single
small resource this is barely cheaper than what is shipped today; at six resources it replaces
33,079 chars with ~3,100.

Costs, stated plainly:

- One extra round-trip on the first query of a conversation, to fetch the dictionary.
- Weaker decode-layer steering. The validator's rejection path absorbs it, but expect the
  rejection rate to rise.
- `describe_query_resource` returns a dictionary that then lives in the conversation and is
  resent per step anyway — the saving is that it is *one* resource's dictionary, chosen by the
  model, not all six.

This should be **additive**. Keep `QueryDataTool` exactly as it is for the single-resource case
where the enums are cheap and worth having.

### 3.2 Make `filterDepth` reachable, and drop enums at the deepest level

`PlanToolSchema::__construct` already takes `$filterDepth = 3`, but `QueryDataTool::schema()`
constructs it with no way to pass one:

```php
return (new PlanToolSchema($this->contract()))->build($schema);
```

The knob exists and nothing can reach it. Thread it through the constructor:

```php
public function __construct(
    private readonly string $resource,
    private readonly ?Authenticatable $user = null,
    private ?SchemaRegistry $registry = null,
    private ?QueryRunner $runner = null,
    private readonly int $filterDepth = 3,
) {}
```

Keeping the default at 3 makes this purely additive. Depth 2 — an `and` of conditions containing
one nested `or` — covers most real queries and is a flat 760-chars-per-level saving on this
fixture, more on a wider one.

Separately, the **deepest** inlined level can drop its column enum (554 → 458 chars per
condition, ~17%), or both enums (→ 337, ~39%). The innermost level is where a schema is least
likely to be doing useful steering and where the cost is repeated most.

This is independent of `ResourceSchema::maxFilterDepth()`, which stays the validator's authority.
Lowering the emitted depth bounds what the model is *shown*, never what a plan is *allowed* to
contain.

### 3.3 Hoist the modal capability profile in `toPrompt()`

`describeColumn()` re-states every capability on every column line. `select` in particular is
emitted for every selectable column — and a declared column is selectable by default, so the
token is only informative when it is *absent*.

Rather than a hardcoded legend, compute the **modal capability profile** for the resource at
render time, state it once, and emit only deviations:

```
Columns — reference these names exactly. Anything not listed does not exist.
Unless noted, a column supports: select, sort, filter(= != in not_in).
- started_at — When the attempt began  +filter(> >= < <= between) +agg(min max) +group(day week month quarter year)
- body — The raw payload as received  select only
```

Measured honestly: on the 8-column workbench fixture this **does not pay** — the legend header
costs more than it saves (569 → 593 chars). The fixed cost of the legend amortizes over column
count, and the win only appears when a dominant profile actually exists. The field report's 39%
figure came from 120 columns with a strongly repeated operator list.

So: implement it data-driven, and let it degrade to today's output when no profile dominates.
Do not hardcode a legend that a given schema might not match.

### 3.4 Give `QueryDataTool` a name

Not a token issue, but it blocks §3.1 and blocks multi-resource use generally.

`Laravel\Ai\Tools\ToolNameResolver::resolve()` is:

```php
return is_callable([$tool, 'name']) ? $tool->name() : class_basename($tool);
```

`QueryDataTool` defines no `name()`, so every instance resolves to the string `QueryDataTool`.
Two resources on one agent produce duplicate tool names, which Anthropic rejects outright
(`400 invalid_request_error — tools: Tool names must be unique`). The same resolver backs
`Gateway\Concerns\InvokesTools::findTool()`, so inbound dispatch would be ambiguous too — the API
just refuses first. The README only ever shows one tool, which is why the documented path does
not hit it.

```php
public function name(): string
{
    return 'query_'.$this->resource;
}
```

Resource names are currently unconstrained, so `ResourceSchema::name()` should validate the shape
providers accept (`^[a-zA-Z0-9_-]{1,128}$`) rather than letting an invalid tool name reach the
API. Validating at declaration time is the better half of this fix: it fails in the developer's
test suite instead of in production.

### 3.5 Ship a way to measure it

A package that asks developers to think about token cost should let them see it:

```bash
php artisan ai-query:cost invoices --user=1
```

printing chars and estimated tokens for the description and the schema, the per-property
breakdown from §1, and the delta at each `filterDepth`. All of it is derivable from
`SchemaContract` with no provider call. This is what turns "your descriptions are too long" from
an opinion into a number the developer can act on, and it is the cheapest item on this list to
build.

### 3.6 Small fixes

`describeColumn()` joins parts with `'. '` without trimming a trailing period, so a description
written the obvious way produces:

```
- id — Primary key.. select, filter(= != in not_in), sort
```

Cosmetic, trivially fixed by trimming before the join, and every consumer will hit it.

## 4. Prompt caching is upstream, and it dominates everything here

`laravel/ai` never sets `cache_control` — `grep -r cache_control vendor/laravel/ai/src` returns
nothing. The identical tool prefix is therefore re-billed at full input price on every step of
every loop. Cache reads are roughly a tenth of input price, so caching a 12,700-token prefix
saves more than every reduction proposed above combined.

That is not this package's bug and cannot be fixed from inside it. Two things are worth doing:

1. **File it upstream on `laravel/ai`**, with the numbers from §1.
2. **Make the payload cache-friendly in the meantime** — a cached prefix only hits if it is
   byte-identical between requests. Anything non-deterministic in `toPrompt()` or the schema
   (map iteration order, an interpolated timestamp, a per-request user label) would silently
   defeat caching the day it lands. Adding `SchemaContract::fingerprint()` — a hash over the
   canonical `toArray()` — makes that testable now: assert the fingerprint is stable across two
   builds for the same user, and the payload is provably cacheable. The same fingerprint is the
   correct cache key for §5.

## 5. Query history and plan reuse

The cheapest plan is one the model never generates. `QueryPlanExecuted` already carries the plan,
the prompt, the user, the row count and the duration — the raw material exists, and the package
deliberately persists none of it. Four distinct ideas live under "reuse", and they are worth
keeping apart because they have different risk profiles.

**Two rules govern all of them:**

- **Cache plans, never rows.** A plan is user-independent; rows are not. Replaying a plan
  re-applies `alwaysScope` under the *current* user, which is exactly the property that makes
  reuse safe. Caching rows would hand one tenant another tenant's data.
- **Key every cache by the contract fingerprint** (§4). A schema edit, or a `visibleWhen` that
  resolves differently for this user, must miss. And always **re-validate** on replay —
  validation costs no tokens, so there is no reason to skip it.

### 5.1 Named saved queries — best return, lowest risk

Promote a validated plan to a named query with typed holes, and expose those as a small second
tool:

```php
'saved' => [
    'weekly_delivery_report' => ['plan' => [...], 'params' => ['since' => 'date']],
],
```

The tool schema is an enum of names plus a handful of typed parameters — a few hundred chars
against the full plan schema's several thousand. Recurring questions ("the weekly report", "open
invoices by customer") stop paying for plan generation entirely, and the parameters are validated
by the same validator as any other plan.

This is the one to build first. It is small, it composes with everything else here, and it has no
correctness hazard the validator does not already handle.

### 5.2 Exact replay — narrow, and honest about why

Hash the normalized prompt, look up the plan, re-validate, re-run. Zero model tokens on a hit.

The hazard is that the same words do not mean the same query over time: "this week", "recent",
"the last 30 days" all bake a resolved literal into the stored plan. A replay of a plan
containing `between ['2026-08-01', '2026-08-07']` will confidently answer last week's question
next month. Mitigations, in order of preference: only cache plans whose literals contain no date
or relative-time value; or template those literals into parameters and promote the plan to §5.1;
or a TTL short enough that the drift cannot matter. A TTL alone is the weakest of the three.

### 5.3 Retrieval few-shot — the interesting pairing

Store `(prompt, plan)` pairs for successful queries, retrieve the top few by similarity, and
inject them as examples. This *adds* tokens per turn, so on its own it is a poor trade.

It becomes interesting paired with §3.1: a handful of concrete worked examples steers the model
at least as well as an exhaustive enum, and unlike the enum it is bounded — three examples cost
the same whether the resource has 8 columns or 800. Worth measuring against rejection rate before
committing to either.

### 5.4 Negative cache

Some questions are structurally refusable — the fan-out aggregate guard rejects "total invoice
value by product type" every single time, at full token cost, every time it is asked. Recording
refusals by `(prompt hash, contract fingerprint, error code)` lets the tool answer immediately
with the explanation and the suggested rephrasing, which is both cheaper and a better experience
than the model rediscovering the wall.

### 5.5 What to persist

Storage stays the application's decision, as it is for auditing today: the package ships the
events and a listener, not a migration and a retention policy. But history and audit want the
same rows, so whatever lands here should be the same opt-in table discussed under "On the audit
table" in `architecture.md` — one decision, not two.

Bound values need care. A history table is a queryable index of every filter value any agent has
ever used, which for the reuse case must be stored, and for the audit case may need redaction.
That tension should be resolved explicitly rather than by whichever feature ships first.

## 6. Corrections to the field report

Two claims in the report that prompted this document do not hold, though its measurements do:

- **"Every column path is enumerated once for `select`, once for `group_by`, once for `sort`."**
  `sort` does not enumerate columns — `PlanToolSchema::sort()` emits a plain string with a
  description, because a sort target may be a select alias rather than a column path. The
  report's own measurement (266 chars) is consistent with this; only the prose is wrong.
- **"The generated JSON schema is 1.9× the size of the prose description."** True of that
  application, but it is a ratio between something that grows with `columns × filterDepth` and
  something that grows with `columns × how much the developer wrote`. It is not a constant, and
  it should not be used to rank the two targets in general.

## 7. Suggested order

1. §3.4 tool naming — a correctness blocker for everything multi-resource, and small.
2. §3.5 the cost command — makes the rest measurable, and is a day's work.
3. §3.2 `filterDepth` reachable — the knob already exists; wiring it is a constructor argument.
4. §4 the upstream `cache_control` issue — filed early, since it dominates and has lead time.
5. §5.1 named saved queries — largest user-visible win that carries no new risk.
6. §3.1 progressive disclosure — the big one, and the one that most needs §3.5's numbers and
   `QueryPlanRejected`'s rates to justify itself.
7. §3.3 modal profile hoisting, §3.6 the double period — cleanup, once there is a way to confirm
   they help.
