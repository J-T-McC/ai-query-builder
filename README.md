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

`POST /ai-query/{resource}/query` with the plan as the JSON body. Returns rows, or 422 with
structured errors. The resource comes from the URL, so a route protected for one resource cannot
be used to query another. Compiled SQL is never returned to the client.

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
- A column hidden from a user is reported as *unknown*, and never appears in a suggestion, so a
  rejection cannot confirm it exists.
- Unknown plan keys are rejected rather than dropped — a silently discarded clause answers a
  question nobody asked while looking like it answered the one they did.
- Truncated results are flagged rather than passed off as complete.

**It does not:**

- Judge whether a question *should* be asked. Authorization of who may query what is your
  middleware and your `alwaysScope` closures.
- Apply Eloquent global scopes to **joined** models — Laravel only applies them to the root
  query. Anything that must hold for joined rows belongs in that relation's `alwaysScope`.
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
