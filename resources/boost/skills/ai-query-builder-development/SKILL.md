---
name: ai-query-builder-development
description: >
  Configure and apply the Ai Query Builder package in Laravel applications so an
  AI agent can query the database through a declared, validated schema.
license: MIT
metadata:
  author: Tyson McCarney
---

# Ai Query Builder

Use this skill when a Laravel application needs an AI agent to query data safely, or when
adopting or configuring the `jtmcc/ai-query-builder` package.

The package is not an AI layer. It validates an untrusted *query plan* against a schema the
developer declares, compiles it to Eloquent with scopes the plan cannot express, and runs it
under limits. It sits underneath whatever AI layer the app already has.

## Primary Goal

- apply the `jtmcc/ai-query-builder` package's public API in the smallest correct way
- expose the least data that answers the question, and never more

## Workflow

### 1. Install and publish config

```bash
composer require jtmcc/ai-query-builder
php artisan vendor:publish --tag="ai-query-builder-config"
```

The only publish tags are `ai-query-builder` and `ai-query-builder-config`. There are no
migrations, views, or assets.

### 2. Scaffold a resource schema

```bash
php artisan ai-query:make-schema "App\Models\Invoice"
```

Writes `app/AiQueries/InvoiceQuerySchema.php` with every column commented out. Options:
`--namespace`, `--path`, `--force`.

Uncomment only the columns the agent genuinely needs. Columns matching
`ai-query-builder.generator.sensitive_columns` are not scaffolded at all.

### 3. Declare capabilities explicitly

A declared column is selectable and nothing else. Each capability is opt-in, on
`ColumnDefinition`:

- `as(string)` — expose under a different name
- `describe(string)`, `measuredIn(string)` — metadata the agent sees
- `enum(array)` — closed set of values
- `typed(string)` — `string` `integer` `number` `boolean` `date` `datetime`. Only needed where the
  model's casts do not already say; filter values are checked against it either way
- `filterable(array)` — permitted operators: `=` `!=` `>` `>=` `<` `<=` `between` `in` `not_in`
  `like` `is_null` `is_not_null`. A date column permitting `between` also derives `within`, which
  takes a named range the package resolves; it needs no declaration
- `aggregatable(array)` — `sum` `avg` `min` `max` `count` `count_distinct`
- `groupable()`, `groupableBy(array)` — `day` `week` `month` `quarter` `year`
- `sortable()`, `selectable(false)`
- `visibleWhen(Closure)` — hide the column from this user entirely

On `ResourceSchema`: `for()`, `name()`, `describe()`, `column()`, `relation()`, `alwaysScope()`,
`defaultLimit()`, `maxLimit()`, `maxRelationDepth()`, `maxFilterDepth()`,
`maxFilterNodes()`.

Always add at least one `alwaysScope()` for tenancy or ownership. It is applied before anything
in a plan and no plan can remove it.

Relations are declared with `relation()` and traversed by dotted path (`lines.product.type`).
`RelationDefinition::alwaysScope()` adds an `ON` condition — needed because Eloquent global
scopes are **not** applied to joined models.

### 4. Register the resource

```php
// config/ai-query-builder.php
'resources' => [
    App\AiQueries\InvoiceQuerySchema::class,
],
```

### 5. Verify what the agent will be told

```bash
php artisan ai-query:describe                    # list registered resources
php artisan ai-query:describe invoices           # prompt text
php artisan ai-query:describe invoices --json    # JSON Schema
php artisan ai-query:describe invoices --user=1  # as that user sees it
```

Use `--user` to confirm a `visibleWhen` column really is hidden. Reading the schema class cannot
tell you that.

### 6. Connect the AI layer

**Laravel AI SDK** (`laravel/ai`, a suggested dependency) — register the shipped tool:

```php
use JTMcC\AiQueryBuilder\Ai\QueryDataTool;

public function tools(): iterable
{
    return [new QueryDataTool('invoices', auth()->user())];
}
```

**Any other AI layer** — run the plan directly:

```php
use JTMcC\AiQueryBuilder\Execution\QueryRunner;

$result = app(QueryRunner::class)->as($user)->run($plan);
```

Build prompt and schema from `SchemaContract::for($schema, $user)`, which exposes `toPrompt()`,
`toJsonSchema()` and `toArray()`.

**HTTP endpoint** — off by default. Set `ai-query-builder.routes.enabled` to true and keep an
auth middleware in `routes.middleware`. Gives `POST {prefix}/{resource}/query`.

### 7. Configure guardrails and auditing

`ai-query-builder.execution` sets defaults for `connection`, `timeout` (ms) and `max_rows`. Each
is overridable per call: `->connection()`, `->timeout()`, `->maxRows()`, `->withPrompt()`.

Listen to the events; the package persists nothing:

- `QueryPlanValidated` — approval-gate hook, fires before compilation
- `QueryPlanRejected` — `errorCodes()` for failure-rate metrics
- `QueryPlanExecuted` — the audit record: plan, SQL, bindings, user, prompt, rows, duration

## Rules, References, and Templates

Read before executing:

- `config/ai-query-builder.php` for every configurable key
- `php artisan ai-query:describe {resource}` to see the real contract before tuning a prompt

Key behaviours to rely on:

- rejections raise `InvalidQueryPlanException`; `toArray()` gives per-path codes and
  `did_you_mean` suggestions suitable for returning to a model
- `QueryRunner::explain($plan)` compiles without executing, for approval flows
- `ResultSet::$truncated` is true when the row cap was hit; never present a truncated result as
  complete
- `ResultSet::$columns` carries units — use them when narrating numbers

## Examples

- **Analytics agent**: declare `issued_at` with `groupableBy(['month'])`, `total` with
  `aggregatable(['sum'])`, add a tenant `alwaysScope`, register `QueryDataTool` on the existing
  agent, then confirm with `ai-query:describe invoices --user=1`.
- **Filter without exposing**: give a sensitive column `filterable([...])->selectable(false)` so
  an agent can slice by it but never read it.
- **Per-user column**: wrap a column in `visibleWhen(fn ($user) => $user?->can('...'))`; it
  disappears from the contract, so the agent never learns it exists.
- **Measuring plan quality**: listen to `QueryPlanRejected` and group by `errorCodes()` before
  deciding whether to add a retry loop.

## Anti-patterns

- do not document package internals here; keep the skill focused on adoption in Laravel apps
- do not expose a resource without an `alwaysScope` for tenancy or ownership
- do not enable `routes.enabled` without an authentication middleware
- do not uncomment a whole generated schema; declare only what is needed
- do not aggregate a parent column while joining a to-many relation — the compiler rejects it
  because the result would be silently inflated; aggregate on the joined side instead
- do not rely on Eloquent global scopes for joined models; use `RelationDefinition::alwaysScope()`
- do not feed query results back into a prompt as trusted input
- do not have the agent compute dates. A date column that permits `between` also accepts the
  `within` operator with a named range (`last_30_days`, `last_month`, `year_to_date`,
  `last_<N>_<seconds|minutes|hours|days|weeks|months|years>`), resolved by the package. Free-text
  relative values such as `now-30d` are rejected — the grammar is closed on purpose
