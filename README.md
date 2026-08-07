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

You declare what an agent may select, filter, join, group and sort. The agent emits a **query
plan** — never SQL. The package checks every part of that plan against your declaration, compiles
it to an Eloquent query, adds scopes the plan cannot touch, and runs it under limits you set.

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

PHP 8.3+ and Laravel 13.16+ — the AI adapter builds its schema with `Illuminate\JsonSchema`, whose
`anyOf()` arrived in 13.16.

## Installation

```bash
composer require jtmcc/ai-query-builder
php artisan vendor:publish --tag="ai-query-builder-config"
```

## Defining a resource

Nothing is exposed until you declare it. A declared column is selectable and nothing else until
you grant each capability.

Scaffold one from a model:

```bash
php artisan ai-query:make-schema "App\Models\Invoice"
```

Every column is written out commented, so opting in is a deliberate edit. Columns whose names look
like secrets are skipped entirely.

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

            // Hidden columns are absent from the contract, so an agent never
            // learns they exist.
            ->column('customer_notes', fn (ColumnDefinition $c) => $c
                ->visibleWhen(fn (?Authenticatable $user) => $user?->can('viewInvoiceNotes') ?? false))

            ->relation('lines', fn (RelationDefinition $r) => $r
                ->column('quantity', fn (ColumnDefinition $c) => $c->aggregatable(['sum']))
                ->relation('product', fn (RelationDefinition $p) => $p
                    ->column('type', fn (ColumnDefinition $c) => $c
                        ->enum(['widget', 'service'])
                        ->filterable(['=', 'in'])
                        ->groupable())))

            // Applied to every query, before anything in the plan.
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

### Column capabilities

| Method | What it grants |
|---|---|
| `as('alias')` | Expose the column under a different name |
| `describe('…')` | A note the agent sees |
| `measuredIn('currency:USD')` | A unit the agent sees, so it narrates numbers correctly |
| `typed('date')` | The value type, when the model's casts don't say |
| `enum([...])` | A closed set of permitted values |
| `filterable([...])` | `=` `!=` `>` `>=` `<` `<=` `between` `in` `not_in` `like` `is_null` `is_not_null` |
| `aggregatable([...])` | `sum` `avg` `min` `max` `count` `count_distinct` |
| `groupable()` / `groupableBy([...])` | Group by the value, or by `day` `week` `month` `quarter` `year` |
| `sortable()` | Sort by the column |
| `selectable(false)` | Usable in filters, never returned |
| `visibleWhen(fn ($user) => …)` | Hide the column from users who fail the check |

Resource-level settings: `defaultLimit()`, `maxLimit()`, `maxRelationDepth()`, `maxFilterDepth()`,
`maxFilterNodes()`.

### Checking what the agent sees

```bash
php artisan ai-query:describe invoices           # the prompt text
php artisan ai-query:describe invoices --json    # the JSON Schema
php artisan ai-query:describe invoices --user=1  # as that user sees it
php artisan ai-query:describe invoices --cost    # what it weighs in tokens
```

Use `--user` to confirm a `visibleWhen` column really is hidden. Reading the schema class cannot
tell you that.

Both halves of the contract are resent on every step of an agent loop, so a resource that is
expensive to describe costs tokens even on turns that never query it. `--cost` breaks that down by
plan property and filter depth.

## Relations

An agent traverses relations by dotted path — `lines.product.type` — and the compiler derives the
joins. An agent cannot express a join itself.

Supported: `hasOne`, `hasMany`, `belongsTo`, `belongsToMany`, `hasOneThrough`, `hasManyThrough`,
`morphOne`, `morphMany`, `morphToMany`.

`morphTo` cannot be joined and raises an error. The table it points at is stored per row rather
than fixed by the schema, so there is nothing to join to. Expose each concrete model as its own
resource instead.

### Join aliases

Every joined table is aliased to its relation path, so two paths can reach the same table without
colliding:

```sql
from "invoices"
left join "invoice_lines" as "lines"          on "invoices"."id" = "lines"."invoice_id"
left join "products"      as "lines__product" on "lines"."product_id" = "lines__product"."id"
```

The root table is not aliased, so a resource-level `alwaysScope` names it directly.

A relation-level `alwaysScope` adds a condition to that relation's `ON` clause. It receives the
alias as its third argument — qualify columns with that, not with the table name:

```php
->relation('lines', fn (RelationDefinition $r) => $r
    ->column('quantity', fn (ColumnDefinition $c) => $c->aggregatable(['sum']))
    ->alwaysScope(fn (JoinClause $join, ?Authenticatable $user, string $alias) => $join
        ->where("{$alias}.quantity", '>', 0)))
```

Laravel does not apply a model's global scopes to a join, so anything that must hold for joined
rows belongs in `alwaysScope`. Soft deletes are the exception — see below.

### Many-to-many

Declare a `belongsToMany` like any other relation:

```php
->relation('tags', fn (RelationDefinition $t) => $t
    ->column('name', fn (ColumnDefinition $c) => $c->filterable(['=', 'in'])->groupable()))
```

It compiles to two joins, the pivot and then the related table:

```sql
left join "invoice_tag" as "tags__pivot" on "invoices"."id" = "tags__pivot"."invoice_id"
left join "tags"        as "tags"        on "tags__pivot"."tag_id" = "tags"."id"
                                        and "tags"."deleted_at" is null
```

It counts as **one** relation against `maxRelationDepth`, and as a to-many join for
[fan-out protection](#known-limits).

To constrain the link rather than the row it points at — `revoked_at`, `is_primary` — use
`alwaysPivotScope`. It lands on the pivot join:

```php
->relation('tags', fn (RelationDefinition $t) => $t
    ->column('name')
    ->alwaysPivotScope(fn (JoinClause $join, ?Authenticatable $user, string $alias) => $join
        ->whereNull("{$alias}.revoked_at")))
```

#### Pivot columns

Attributes of the link itself live on the pivot table. Declare them with `pivot()`:

```php
->relation('tags', fn (RelationDefinition $t) => $t
    ->column('name', fn (ColumnDefinition $c) => $c->filterable(['=', 'in']))
    ->pivot(fn (PivotDefinition $p) => $p
        ->column('assigned_at', fn (ColumnDefinition $c) => $c
            ->typed('date')
            ->filterable(['=', 'between']))))
```

An agent addresses them under a `pivot` segment, which keeps them distinct from a related column
of the same name:

```
tags.name                 → the tag
tags.pivot.assigned_at    → the link to it
```

`pivot` is a reserved name; declaring a relation called `pivot` raises an error. The segment does
not count against `maxRelationDepth`.

Types are not read from casts on a pivot, because the pivot table usually has no model. Declare
them with `typed()`. It's worth doing: a typed date column also gets `within` support and
filter-value checking.

### Through relations

`hasManyThrough` and `hasOneThrough` compile to two joins, the intermediate table and then the far
one:

```sql
left join "invoices"      as "lines__through" on "customers"."id" = "lines__through"."customer_id"
                                             and "lines__through"."deleted_at" is null
left join "invoice_lines" as "lines"          on "lines__through"."id" = "lines"."invoice_id"
```

The intermediate table's soft deletes are applied, so a row hanging off a deleted intermediate is
not reachable through it.

Both count as one relation against `maxRelationDepth`. `hasManyThrough` counts as to-many for
fan-out; `hasOneThrough` does not.

### Polymorphic relations

`morphOne`, `morphMany` and `morphToMany` carry their type condition on the join, so a table shared
by several parent types only ever returns the rows belonging to this one:

```sql
-- morphMany: the type sits on the related table
left join "notes" as "notes" on "invoices"."id" = "notes"."notable_id"
                            and "notes"."notable_type" = ?

-- morphToMany: the type sits on the pivot
left join "taggables" as "tags__pivot" on "invoices"."id" = "tags__pivot"."taggable_id"
                                      and "tags__pivot"."taggable_type" = ?
```

### Soft deletes

Deleted rows are excluded from joined relations as well as from the root model. The condition goes
on the `ON` clause:

```sql
left join "invoice_lines" as "lines"
       on "invoices"."id" = "lines"."invoice_id"
      and "lines"."deleted_at" is null
```

On the `ON` clause rather than the `WHERE` clause, so a left join stays a left join: an invoice
whose only line was deleted still appears, with nulls where the line would have been.

The column name is read from the model, so `DELETED_AT` overrides work without configuration.

To include deleted rows — an audit view, a report on cancellations — opt in per relation:

```php
->relation('lines', fn (RelationDefinition $r) => $r
    ->withTrashed()
    ->column('quantity', fn (ColumnDefinition $c) => $c->aggregatable(['sum'])))
```

There is no `onlyTrashed()`. Declare `deleted_at` as a filterable column instead, so the plan says
what it wants out loud.

## Query plans

An agent emits a plan. There is no `raw`, no `expression`, no `sql` key — not behind a config flag,
not anywhere.

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

### Filter values

A filter value must match the kind of value the column holds. The type comes from the model's
casts, or from `->typed()`. An unrecognised cast means no type and no check, so type inference
never rejects a plan it simply could not read.

This matters because the wrong kind of value doesn't fail on its own:

```json
{ "column": "issued_at", "operator": ">=", "value": "now-30d" }
```

Nothing evaluates that string. MySQL reads it as a zero date and matches every row; SQLite compares
it as text and matches none. Either way the query succeeds and the agent reports the result as the
answer. The package rejects it with `value_type_mismatch`.

### Date ranges

A date column that permits `between` also accepts `within`, which names a range the package
resolves. The agent does no calendar arithmetic:

```json
{ "column": "issued_at", "operator": "within", "value": "last_30_days" }
```

```
today            yesterday        this_week        last_week
this_month       last_month       this_quarter     last_quarter
this_year        last_year        month_to_date    quarter_to_date
year_to_date     last_<N>_<seconds|minutes|hours|days|weeks|months|years>
```

Named windows follow calendar boundaries — `last_month` is the whole of the previous month.
`last_<N>_<unit>` counts back from now. Bounds are inclusive, and a date column is bound as a bare
date so the boundary day isn't dropped. A window shorter than a day is rejected on a column that
stores no time.

`within` needs no declaration. It compiles to the same bounded comparison `between` already
permits, so it grants no reach you haven't already granted.

The grammar is closed — anything outside the list above is rejected, with `did_you_mean` pointing
at the window that was probably meant.

The window stays in the plan and resolves on each run, so a stored plan means the last 30 days
*today*, not the last 30 days as of when it was written.

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

`explain($plan)` validates and compiles without executing, returning SQL and bindings for approval
or logging.

Rejections throw `InvalidQueryPlanException` carrying every error at once, so a plan can be fixed
in one pass:

```json
{ "path": "select.1.column", "code": "unknown_column",
  "message": "Unknown column [totl].", "did_you_mean": "total" }
```

## Connecting an AI layer

### Laravel AI SDK

Register the shipped tool on an agent you already have:

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

The tool's description is the data dictionary for that user, and its schema constrains the model
while it decodes. A rejection is returned to the model rather than thrown, so it can correct
itself — which makes retries the agent loop's decision. Add `#[MaxSteps(1)]` for a single attempt.

`laravel/ai` is a suggested dependency. The package works without it.

#### Several resources

Each tool carries its resource's full dictionary and column list on every step of the loop, so six
resources means six dictionaries on every step — including the turn where the user says hello.

Two tools replace that with a short list and one round-trip:

```php
use JTMcC\AiQueryBuilder\Facades\AiQueryBuilder;

public function tools(): iterable
{
    return AiQueryBuilder::tools(['invoices', 'customers'], auth()->user());
}
```

`describe_query_resource` carries only the resource names and returns one dictionary when asked.
`query_data` carries a plan schema that describes columns rather than listing them, so it doesn't
grow as you add resources or columns.

The trade-off: without enums, nothing constrains column names while the model decodes, so expect
more rejections and corrections. It's an accuracy trade, not a safety one — the validator is
unchanged, and a column hidden from a user is still reported as unknown.

For the smaller schema without the round-trip:

```php
new QueryDataTool('invoices', auth()->user(), detail: PlanSchemaDetail::Generic)
```

One caveat with `Generic`: enums steer filter *values* as a side effect, so a filterable column
with no declared or inferred type has nothing checking what goes into it. Add `->typed()` to those
columns. See [Filter values](#filter-values).

Compare both with `ai-query:describe invoices --cost` before choosing.

#### Prompt caching

**Do this before shrinking anything.** The tool payload is a good cache target: large, rendered at
the front of the prefix, and byte-identical between requests. A warm cache bills the prefix at
roughly a tenth — a bigger saving than any wire-format tuning.

`laravel/ai` doesn't set `cache_control` itself, but it merges provider options into the request:

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

Caching is a prefix match, so one changed byte before the breakpoint re-bills everything after it.
There's no error when this happens — just a larger invoice, with the cache written every request
and read never. A timestamp in your agent's instructions is the usual culprit:

```php
str_replace('{NOW}', now()->toDateTimeString(), $prompt)   // invalidates every request
str_replace('{TODAY}', now()->toDateString(), $prompt)     // stable for a day
```

Better still, keep the time out of the prefix. [Date ranges](#date-ranges) exist partly for this:
an agent that says `within: last_30_days` never needs to be told today's date.

This package renders `toPrompt()` and the plan schema identically for the same contract and user on
every build, which is what makes the payload cacheable. Tests hold that guarantee, and
`SchemaContract::fingerprint()` lets you assert it in your own suite.

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

`columns` carries the metadata needed to narrate the rows, keyed by result alias — a number without
its unit is how a model ends up reporting cents as dollars. `truncated` says whether the row cap
was hit.

Rejected plans return `422` with structured errors. An unknown resource returns `404` without
listing the ones that exist, so a probe can't enumerate them. The resource comes from the URL and
overrides any `resource` in the body, so a route protected for one resource can't be used to query
another. Compiled SQL is never returned; use `QueryRunner::explain()` server-side for that.

#### A plan is a saved query, not a saved result

Running a plan is stateless, so a plan an agent wrote once can be stored and re-posted whenever you
want the answer again. The expensive step — a model turning a question into a plan — happens once.
Every run after that is a database query with no model involved.

```php
$plan = $agent->generatePlan('revenue by status over the last 30 days');

SavedQuery::create(['user_id' => $user->id, 'name' => 'Revenue by status', 'plan' => $plan]);
```

Re-run it from a dashboard panel, a scheduled export, or a refresh button:

```js
const res = await fetch(`/ai-query/invoices/query`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(savedQuery.plan),
})
```

Rolling date ranges are what make this a live query rather than a snapshot. A stored plan holding
`"within": "last_30_days"` resolves on every run, so the same stored bytes mean the thirty days
ending today. Store `{"operator": ">=", "value": "2026-07-07"}` instead and you've frozen a moment.

Stored plans stay safe because:

- **They're re-validated every run.** Remove a column, revoke an operator or lower a row cap and
  every stored plan that relied on it starts failing with a structured error.
- **They resolve against the caller, not the author.** A plan shared between users is re-checked
  against each one's visibility, so a column the caller can't see comes back as `unknown_column`.
- **Mandatory scopes apply every run.** `alwaysScope` isn't part of the plan, so a stored plan
  can't escape the tenancy it was created under.

A stored plan is also readable and diffable, so you can show users what a saved query does and grep
your storage for every plan touching a column before you drop it.

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
and truncation flag. The package persists nothing and ships no migration: retention and redaction
are your application's decisions.

```php
Event::listen(QueryPlanRejected::class, function ($event) {
    Log::channel('ai-queries')->info('rejected', [
        'resource' => $event->resource,
        'codes' => $event->errorCodes(),
    ]);
});
```

`QueryPlanValidated` fires after validation and before compilation — the hook for an approval gate.
`QueryPlanRejected` carries error codes, so you can measure how often an agent produces an invalid
plan before deciding whether retries are worth the tokens.

## What this protects against, and what it doesn't

**It does:**

- An agent cannot reference a column, relation, operator or function you did not declare.
- An agent cannot remove a mandatory scope. Its filters are wrapped in a group, so a top-level `or`
  cannot escape a tenant scope.
- Values are always bound as parameters. Identifiers are built from your schema, never from plan
  strings.
- A tool queries the resource it was built for. A plan naming a different resource cannot redirect
  it, so which resources an agent may touch stays a decision about which tools you register.
- A column hidden from a user is reported as unknown and never appears in a suggestion, so a
  rejection cannot confirm it exists.
- Unknown plan keys are rejected rather than ignored, so a dropped clause never looks like an
  answered one.
- A filter value of the wrong kind is rejected rather than bound. See
  [Filter values](#filter-values).
- Soft-deleted rows are excluded from joined relations, not just the root model.
- A polymorphic join always carries its type condition, so a shared table never returns another
  model's rows.
- Truncated results are flagged rather than passed off as complete.

**It does not:**

- Judge whether a question *should* be asked. Who may query what is your middleware and your
  `alwaysScope` closures.
- Apply Eloquent global scopes to joined models — Laravel only applies them to the root query. Soft
  deletes are handled explicitly; anything else belongs in that relation's `alwaysScope`.
- Make query results trustworthy input for a later turn. Rows can contain user-written text; treat
  them as untrusted when they flow back into a prompt.
- Rate limit. Add that at your middleware or agent layer.

## Known limits

- **Fan-out aggregates are refused.** Aggregating an invoice column while joining to its lines
  would count each invoice once per line and return a plausible but inflated number. Aggregate a
  column on the joined side instead.
- `morphTo` cannot be joined and is rejected.
- Pivot column types are not inferred. Declare them with `typed()`.
- A through relation's intermediate table is joined but not addressable, the way `pivot` addresses
  a many-to-many's.
- Statement timeouts require pgsql, mysql or mariadb. On other drivers a non-null timeout raises
  rather than being silently ignored.
- No unions, no pagination, no cross-resource joins yet.
- The JSON Schema does not encode which operators go with which column. That precision lives in the
  prompt text and is enforced by the validator.

## Testing

```bash
composer test          # analyse, lint, type coverage, tests
composer test:unit     # Pest only
```
