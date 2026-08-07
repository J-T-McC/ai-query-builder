<div align="center">
    <h1>Ai Query Builder</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/jtmcc/ai-query-builder"><img src="https://img.shields.io/packagist/v/jtmcc/ai-query-builder.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/jtmcc/ai-query-builder"><img src="https://img.shields.io/packagist/php-v/jtmcc/ai-query-builder.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/jtmcc/ai-query-builder"><img src="https://badge.laravel.cloud/badge/jtmcc/ai-query-builder?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/j-t-mcc/ai-query-builder/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/j-t-mcc/ai-query-builder/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/jtmcc/ai-query-builder"><img src="https://img.shields.io/packagist/dt/jtmcc/ai-query-builder.svg?style=flat-square" alt="Total Downloads"></a>
</p>

A safe boundary between an AI agent and your database.

You declare exactly what an agent may select, filter, join, group and sort. It emits a **query
plan** — never SQL. The package validates every token in that plan against your declaration,
compiles it to an Eloquent query with scopes the plan cannot express, and runs it under explicit
limits.

**This is not an AI layer.** It sits underneath whichever one you already have.

```
user prompt ──▶ [ your AI layer ] ──▶ query plan (untrusted JSON)
                        ▲                      │
                        │                      ▼
                    contract               VALIDATE ──✗──▶ structured errors
                        ▲                      │
                        └──────────────  COMPILE (+ mandatory scopes)
                                                │
                                                ▼
                                          EXECUTE (guarded) ──▶ rows
```

## Requirements

PHP 8.3+ and Laravel 13.16+. The AI adapter builds its schema with
`Illuminate\JsonSchema`, whose `anyOf()` the nested filter schema depends on, and that
arrived in 13.16.

## Installation

```bash
composer require jtmcc/ai-query-builder
php artisan vendor:publish --tag="ai-query-builder-config"
```

## Defining a resource

Nothing is exposed until you declare it. A declared column is selectable and *nothing else* until
you grant each capability explicitly.

```bash
php artisan ai-query:make-schema "App\Models\Invoice"
```

That writes a draft with every column commented out — opting in is a deliberate edit. Columns
whose names suggest secrets are not scaffolded at all.

```php
namespace App\AiQueries;

use App\Models\Invoice;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use JTMcC\AiQueryBuilder\Schema\ColumnDefinition;
use JTMcC\AiQueryBuilder\Schema\Contracts\DefinesQuerySchema;
use JTMcC\AiQueryBuilder\Schema\RelationDefinition;
use JTMcC\AiQueryBuilder\Schema\ResourceSchema;

final class InvoiceQuerySchema implements DefinesQuerySchema
{
    public function define(ResourceSchema $schema): ResourceSchema
    {
        return $schema
            ->for(Invoice::class)
            ->name('invoices')
            ->describe('Customer invoices, one row per invoice.')

            ->column('id', fn (ColumnDefinition $c) => $c->as('invoice_id')->sortable())

            // The type comes from the model's casts, so a filter value that is
            // not a real date is rejected. Declare it with ->typed('date') only
            // where the casts do not already say.
            ->column('issued_at', fn (ColumnDefinition $c) => $c
                ->describe('Date the invoice was issued')
                ->filterable(['=', '>', '<', '>=', '<=', 'between'])
                ->groupableBy(['day', 'month', 'quarter', 'year'])
                ->sortable())

            ->column('total', fn (ColumnDefinition $c) => $c
                ->measuredIn('currency:USD')
                ->aggregatable(['sum', 'avg', 'min', 'max'])
                ->filterable(['>', '<', 'between'])
                ->sortable())

            ->column('status', fn (ColumnDefinition $c) => $c
                ->enum(['draft', 'sent', 'paid', 'void'])
                ->filterable(['=', 'in'])
                ->groupable())

            // Filterable but never selectable: an agent can slice by margin
            // without ever seeing it.
            ->column('internal_margin', fn (ColumnDefinition $c) => $c
                ->filterable(['>', '<'])
                ->selectable(false))

            // Hidden unless the check passes. A hidden column is absent from the
            // contract entirely, so an agent never learns it exists.
            ->column('customer_notes', fn (ColumnDefinition $c) => $c
                ->visibleWhen(fn (?Authenticatable $user) => $user?->can('viewInvoiceNotes') ?? false))

            // Traversal is by declared relation only. An agent cannot express a join.
            ->relation('lines', fn (RelationDefinition $r) => $r
                ->column('quantity', fn (ColumnDefinition $c) => $c->aggregatable(['sum']))
                ->relation('product', fn (RelationDefinition $p) => $p
                    ->column('type', fn (ColumnDefinition $c) => $c
                        ->enum(['widget', 'service'])
                        ->filterable(['=', 'in'])
                        ->groupable())))

            // Applied to every query, before anything in a plan. No plan can
            // express these, so no plan can remove them.
            ->alwaysScope(fn (Builder $query, ?Authenticatable $user) => $query
                ->where('invoices.tenant_id', $user?->tenant_id))

            ->defaultLimit(100)
            ->maxLimit(1000);
    }
}
```

Register it:

```php
// config/ai-query-builder.php
'resources' => [
    App\AiQueries\InvoiceQuerySchema::class,
],
```

Check what an agent will actually be told — including as a specific user, which is the only way
to confirm a gated column is really hidden:

```bash
php artisan ai-query:describe invoices --user=1
php artisan ai-query:describe invoices --json
```

Both halves of that contract — the dictionary and the plan schema — are resent on every step of
an agent loop, so a resource that is expensive to describe is expensive on turns that never query
it. `--cost` reports what it weighs, broken down by plan property and by filter depth:

```bash
php artisan ai-query:describe invoices --cost
```

### Join aliases

Every joined table is aliased to its relation path. `lines` is joined as `lines`, and
`lines.product` as `lines__product`:

```sql
from "invoices"
left join "invoice_lines" as "lines"          on "invoices"."id" = "lines"."invoice_id"
left join "products"      as "lines__product" on "lines"."product_id" = "lines__product"."id"
```

Without this, two paths reaching the same table compile to two joins of that table and every
column reference between them is ambiguous — `author.company.name` alongside
`publisher.company.name` is a query the database refuses to run. Aliasing also lets a relation
reach a table that is already the root.

Every join is aliased, including the ones that would not collide. An alias that appeared only
when it was needed would mean a relation scope worked until the day someone declared a second path
to the same table.

The root is **not** aliased, so a resource-level `alwaysScope` keeps naming the real table.

A relation-level `alwaysScope` receives the alias as its third argument, and should qualify
columns with it rather than with the table name:

```php
->relation('lines', fn (RelationDefinition $r) => $r
    ->column('quantity', fn (ColumnDefinition $c) => $c->aggregatable(['sum']))
    ->alwaysScope(fn (JoinClause $join, ?Authenticatable $user, string $alias) => $join
        ->where("{$alias}.quantity", '>', 0)))
```

Aliases are derived from the path alone, so the same plan always compiles to the same SQL.

### Soft deletes

Deleted rows are excluded everywhere, not just on the root model.

Eloquent applies `SoftDeletingScope` to the root query, but Laravel applies no global scope to a
join — so without help, a plan reading `lines.product.name` would read products deleted a year
ago. The compiler adds the condition itself, on the join's `ON` clause:

```sql
left join "invoice_lines" as "lines"
       on "invoices"."id" = "lines"."invoice_id"
      and "lines"."deleted_at" is null
```

On the `ON` clause rather than in the `WHERE` clause, because the difference is visible: in the
`WHERE` clause this would turn every left join into an inner one, and an invoice whose only line
was deleted would vanish from the report. On the `ON` clause the invoice stays and the line reads
as null.

The column comes from the model, so a model that renames it through `DELETED_AT` works without
configuration. Nothing about this reaches the agent — it is invisible policy, like `alwaysScope`,
and costs no contract tokens.

If a resource genuinely needs the deleted rows — an audit view, a report on cancellations — opt in
per relation:

```php
->relation('lines', fn (RelationDefinition $r) => $r
    ->withTrashed()
    ->column('quantity', fn (ColumnDefinition $c) => $c->aggregatable(['sum'])))
```

There is no `onlyTrashed()`. Declare `deleted_at` as a filterable column instead and let the plan
say so out loud.

## Query plans

An agent emits a plan. There is no `raw`, no `expression`, no `sql` key — not behind a config
flag, not anywhere.

```json
{
  "resource": "invoices",
  "select": [
    { "column": "lines.product.type", "as": "product_type" },
    { "column": "lines.quantity", "function": "sum", "as": "total_qty" }
  ],
  "filters": {
    "operator": "and",
    "conditions": [
      { "column": "issued_at", "operator": "between", "value": ["2026-01-01", "2026-03-31"] },
      { "column": "lines.product.type", "operator": "=", "value": "widget" }
    ]
  },
  "group_by": [{ "column": "lines.product.type" }],
  "sort": [{ "column": "total_qty", "direction": "desc" }],
  "limit": 50
}
```

Columns are relation paths. `lines.product.type` implies the joins; the compiler derives them
from your declared relations.

### Filter values

A filter value must be of the kind the column holds. The type is read from the model's casts, or
declared with `->typed()` when the casts do not say. An unrecognised cast means no type and no
check, so inference never rejects a plan it merely failed to understand.

This exists because the wrong kind of value does not fail anywhere. It validates, binds, runs, and
the comparison quietly means something else:

```json
{ "column": "issued_at", "operator": ">=", "value": "now-30d" }
```

Nothing evaluates that string. MySQL casts it to a zero date and matches every row; SQLite
compares it as text and matches none. The query succeeds either way and the agent narrates the
result as the answer. It is rejected with `value_type_mismatch`.

### Date ranges

An agent should not be doing calendar arithmetic, so it doesn't have to. A date column that
permits `between` also accepts `within`, which names a range the package resolves:

```json
{ "column": "issued_at", "operator": "within", "value": "last_30_days" }
```

```
today            yesterday        this_week        last_week
this_month       last_month       this_quarter     last_quarter
this_year        last_year        month_to_date    quarter_to_date
year_to_date     last_<N>_<seconds|minutes|hours|days|weeks|months|years>
```

Named windows use calendar boundaries — `last_month` is the whole of the previous month, and
stepping back from the 1st means `last_month` on the 31st of March is February, not March 3rd.
`last_<N>_<unit>` rolls back from this instant. Bounds are inclusive, and a date column is bound
as a bare date so the boundary day is not dropped. A window shorter than a day is refused on a
column that stores no time, rather than quietly collapsing to "today".

`within` needs no declaration: it compiles to the same bounded comparison `between` already
permits, so it grants no reach you did not already grant.

**The grammar is closed, and that is the point.** Feeding these strings to a general parser is
worse, measurably: `strtotime` resolves `now-30d` — the string that caused this — to thirty
*hours* in the future, reads `01/02/2026` as January whatever the writer meant, and turns
`last month` into a point in time a month back rather than the month itself, while rejecting
`last 30 days`, `this quarter` and `year to date` outright. Everything it wrongly rejects is
named above; everything it wrongly accepts is refused, with `did_you_mean` pointing at the window
that was meant.

**The window stays in the plan.** It resolves on each validation, not into the plan, so a stored
plan replayed next week means the week that has passed rather than the one that had passed when
it was written. That is what makes a plan worth caching.

## Running a plan

```php
use JTMcC\AiQueryBuilder\Execution\QueryRunner;

$result = app(QueryRunner::class)
    ->as($user)
    ->connection('analytics_readonly')  // optional read-only replica
    ->timeout(5_000)                    // milliseconds
    ->maxRows(1_000)
    ->withPrompt($prompt)               // carried into audit events
    ->run($plan);

$result->rows;       // list of associative arrays
$result->columns;    // per-alias unit and description metadata
$result->truncated;  // true when the cap was hit
```

`explain($plan)` validates and compiles without executing, returning SQL and bindings for
human approval or logging.

Rejections throw `InvalidQueryPlanException`, carrying every error at once so a plan can be
corrected in one pass:

```json
{ "path": "select.1.column", "code": "unknown_column",
  "message": "Unknown column [totl].", "did_you_mean": "total" }
```

## Connecting an AI layer

### Laravel AI SDK

Register the shipped tool on an agent you already have. That is the whole integration.

```php
use JTMcC\AiQueryBuilder\Ai\QueryDataTool;

class AnalystAgent implements Agent, HasTools
{
    use Promptable;

    public function tools(): iterable
    {
        return [new QueryDataTool('invoices', auth()->user())];
    }
}
```

The tool's description is the data dictionary for that user, its schema constrains the model at
the decoding layer, and a rejection is returned to the model rather than thrown so it can
correct itself. That makes retries the agent loop's decision — add `#[MaxSteps(1)]` for strictly
one attempt.

`laravel/ai` is a suggested dependency. The package works without it.

#### Several resources

A tool's description is its resource's whole data dictionary, and its schema enumerates every
column. Both are resent on every step of the agent loop, so registering six resources means six
dictionaries on every step — including the turn where the user says hello.

Two tools replace that with a short list plus one round-trip:

```php
use JTMcC\AiQueryBuilder\Facades\AiQueryBuilder;

public function tools(): iterable
{
    return AiQueryBuilder::tools(['invoices', 'customers'], auth()->user());
}
```

`describe_query_resource` carries only the resource names and returns one dictionary when asked.
`query_data` carries a plan schema that describes columns instead of enumerating them, so it does
not grow as you add resources or columns. Build them from one array — that array is the boundary,
the way registering one tool per resource used to be.

The trade is real: without enums, nothing constrains column names at the decoding layer, so
expect more rejections and more corrections. It is an accuracy trade, not a safety one — the
validator is unchanged, and a column hidden from a user is still reported as *unknown*. Take the
schema alone on a single-resource tool if you want it without the round-trip:

```php
new QueryDataTool('invoices', auth()->user(), detail: PlanSchemaDetail::Generic)
```

Measure both with `ai-query:describe invoices --cost` before choosing.

One caveat specific to `Generic`. Enums steer filter *values* as a side effect, and dropping them
takes that away, so a filterable column whose type is neither declared nor implied by the model's
casts has nothing checking what goes into it. `->typed()` on those columns is what makes `Generic`
as safe as `Enumerated` rather than only cheaper. See [Filter values](#filter-values).

#### Prompt caching

**Do this before shrinking anything.** The tool payload is close to the ideal cache target — it is
large, it renders at the front of the prefix, and it is byte-identical between requests. A warm
cache bills the whole prefix at roughly a tenth, which is a bigger lever than every wire-format
saving in this package combined.

`laravel/ai` never sets `cache_control` itself, but it merges provider options into the request
body, so an agent can ask for it:

```php
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;

class AnalystAgent implements Agent, HasProviderOptions, HasTools
{
    public function providerOptions(Lab|string $provider): array
    {
        return match ($provider) {
            Lab::Anthropic => ['cache_control' => ['type' => 'ephemeral']],
            default => [],
        };
    }
}
```

**The trap is that caching is a prefix match.** One byte different anywhere before the breakpoint
re-bills everything after it at full price, and the symptom is not an error — it is a larger
invoice, with a cache being written on every request and read on none. So a volatile value
anywhere in your prefix, most commonly a timestamp in your agent's instructions, costs you the
entire benefit:

```php
str_replace('{NOW}', now()->toDateTimeString(), $prompt)   // invalidates every request
str_replace('{TODAY}', now()->toDateString(), $prompt)     // stable for a day
```

Better still, do not put the time in the prefix at all. [Date ranges](#date-ranges) exist partly
for this: an agent that says `within: last_30_days` never needs to be told what today is.

**What this package guarantees for you:** `toPrompt()` and the plan schema render identically for
the same contract and the same user, every build. That is what makes the payload cacheable at all,
so it is held by tests rather than left to luck — see the `byte stability` block in
`tests/Unit/Contract/SchemaContractTest.php`. `SchemaContract::fingerprint()` is the same promise
in a form you can assert on in your own suite.

Cache first, then shrink. With a warm cache the prefix bills at about a tenth, so trimming it is
worth roughly a tenth of what it was worth uncached — but cold is every first request and every
change to the prefix, and `Generic` and `filterDepth` still cut that.

### Anything else

```php
$plan = json_decode($whateverYourModelReturned, true);
$rows = app(QueryRunner::class)->as($user)->run($plan);
```

Build the prompt and schema from the contract:

```php
use JTMcC\AiQueryBuilder\Contract\SchemaContract;
use JTMcC\AiQueryBuilder\Schema\SchemaRegistry;

$contract = SchemaContract::for(app(SchemaRegistry::class)->get('invoices'), $user);

$contract->toPrompt();      // data dictionary text
$contract->toJsonSchema();  // JSON Schema for the plan
```

### HTTP endpoint

Off by default. Enable it only if you want an HTTP surface, and authenticate it — the configured
middleware is the only thing between this endpoint and the internet.

```php
// config/ai-query-builder.php
'routes' => [
    'enabled' => true,
    'prefix' => 'ai-query',
    'middleware' => ['api', 'auth'],
],
```

`POST /ai-query/{resource}/query` with the plan as the JSON body:

```json
{
  "select": [
    { "column": "status" },
    { "column": "total", "function": "sum", "as": "revenue" }
  ],
  "filters": {
    "operator": "and",
    "conditions": [{ "column": "issued_at", "operator": "within", "value": "last_30_days" }]
  },
  "group_by": [{ "column": "status" }],
  "sort": [{ "column": "revenue", "direction": "desc" }]
}
```

```json
{
  "resource": "invoices",
  "columns": { "status": [], "revenue": { "unit": "currency:USD" } },
  "rows": [{ "status": "paid", "revenue": 42.5 }],
  "row_count": 1,
  "truncated": false
}
```

`columns` carries the metadata needed to narrate the rows correctly, keyed by result alias — a
number without its unit is how a model ends up reporting cents as dollars. A column with no
metadata to report is empty. `truncated` says whether the row cap was hit, so a capped result is
never mistaken for a complete one.

`422` with structured errors if the plan is rejected, `404` for an unknown resource — without
listing the ones that do exist, so an unauthenticated probe cannot enumerate them. The resource
comes from the URL and overrides any `resource` in the body, so a route protected for one resource
cannot be used to query another. Compiled SQL is never returned; it would disclose table and column
names to a client that only needs rows. Use `QueryRunner::explain()` server-side for that.

#### A plan is a saved query, not a saved result

This is the part worth knowing about. A plan is data, and running one is stateless — so a plan an
agent wrote once can be stored and re-posted whenever you want the answer again. The expensive,
non-deterministic step (a model turning a question into a plan) happens once; every run after that
is a database query with no model involved.

That makes a natural split. Generate with an agent, then keep the plan:

```php
$plan = $agent->generatePlan('revenue by status over the last 30 days');

SavedQuery::create(['user_id' => $user->id, 'name' => 'Revenue by status', 'plan' => $plan]);
```

Re-run it from anywhere — a dashboard panel refreshing on an interval, a scheduled export, a
"refresh" button — by posting the stored plan back:

```js
const res = await fetch(`/ai-query/invoices/query`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(savedQuery.plan),
})
```

**Rolling date ranges are what make this a live query rather than a snapshot.** A stored plan
holding `"within": "last_30_days"` is resolved at *validation* time, on every run — so the same
stored bytes mean the thirty days ending today, not the thirty days that had ended when the agent
wrote it. Store `{"operator": ">=", "value": "2026-07-07"}` instead and you have frozen a moment;
store `within` and you have saved a question. See [Date ranges](#date-ranges).

Three properties make stored plans safe to keep around:

- **Re-validated on every run, never trusted because it ran before.** A plan is checked against the
  schema each time. If you remove a column, revoke an operator, or lower a row cap, every stored
  plan that relied on it starts failing with a structured error rather than continuing to work.
- **Resolved against the caller, not the author.** Validation uses `$request->user()`, so a plan
  shared between users is re-checked against each one's visibility. A column the current caller
  cannot see comes back as `unknown_column` — the same answer they would get if it did not exist.
- **Mandatory scopes apply on every run.** `alwaysScope` is not part of the plan and cannot be
  expressed in one, so a stored plan cannot outlive or escape the tenancy it was created under.

The stored plan is also readable and diffable, which an opaque saved query rarely is. Users can be
shown what a saved query actually does, and you can grep your own storage for every plan that
touches a column before you drop it.

## In practice

Two turns against a real application — a webhook proxy service — with one resource declared and
`QueryDataTool` registered on an existing agent.

<p align="center">
    <img src="https://raw.githubusercontent.com/J-T-McC/ai-query-builder/main/art/example-1.png" width="760" alt="An agent listing proxies, noting that retired ones were excluded and that the result was not truncated">
</p>

<p align="center">
    <img src="https://raw.githubusercontent.com/J-T-McC/ai-query-builder/main/art/example-2.png" width="760" alt="An agent producing a delivery report from four separate query plans">
</p>

## Auditing

`QueryPlanExecuted` is the audit record — plan, SQL, bindings, user, prompt, row count, duration
and truncation flag. The package persists nothing and ships no migration: retention and
redaction are decisions only your application can make.

```php
Event::listen(QueryPlanRejected::class, function ($event) {
    Log::channel('ai-queries')->info('rejected', [
        'resource' => $event->resource,
        'codes' => $event->errorCodes(),
    ]);
});
```

`QueryPlanValidated` fires after validation and before compilation — the hook for an approval
gate. `QueryPlanRejected` carries error codes so you can measure how often an agent produces an
invalid plan before deciding whether retries are worth the tokens.

## What this protects against, and what it doesn't

**It does:**

- An agent cannot reference a column, relation, operator or function you did not declare.
- An agent cannot remove a mandatory scope. Its filters are wrapped in a group, so a top-level
  `or` cannot escape a tenant scope.
- Values are always bound as parameters. Identifiers are built from your schema, never from plan
  strings.
- A tool queries the resource it was constructed for. A plan naming a different registered
  resource cannot redirect it, so which resources an agent may touch stays your decision about
  which tools to register.
- A column hidden from a user is reported as *unknown*, and never appears in a suggestion, so a
  rejection cannot confirm it exists.
- Unknown plan keys are rejected rather than dropped — a silently discarded clause answers a
  question nobody asked while looking like it answered the one they did.
- A filter value that is not the kind of thing the column holds is rejected rather than bound.
  See [Filter values](#filter-values).
- Soft-deleted rows are excluded from joined relations, not just from the root model.
  See [Soft deletes](#soft-deletes).
- Truncated results are flagged rather than passed off as complete.

**It does not:**

- Judge whether a question *should* be asked. Authorization of who may query what is your
  middleware and your `alwaysScope` closures.
- Apply Eloquent global scopes to **joined** models — Laravel only applies them to the root
  query. Soft deletes are the one exception, handled explicitly. Anything else that must hold for
  joined rows belongs in that relation's `alwaysScope`.
- Make query results trustworthy input for a later turn. Rows can contain text written by users;
  treat them as untrusted when they flow back into a prompt.
- Rate limit. Add that at your middleware or agent layer.

## Known limits

- **Fan-out aggregates are refused.** Aggregating an invoice column while joining to its lines
  would count each invoice once per line and return a plausible, inflated number, so the compiler
  rejects it. Aggregate a column on the joined side instead.
- `BelongsToMany` traversal is not supported, and is rejected rather than compiled into something
  subtly wrong.
- Statement timeouts require pgsql, mysql or mariadb. On other drivers a non-null timeout raises
  rather than being silently ignored.
- No unions, no pagination, no cross-resource joins yet.
- The JSON Schema does not encode which operators go with which column — that precision lives in
  the prompt text and is enforced by the validator.

## Testing

```bash
composer test          # analyse, lint, type coverage, tests
composer test:unit     # Pest only
```
