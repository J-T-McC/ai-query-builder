# Release Notes

## [v0.3.0](https://github.com/j-t-mcc/ai-query-builder/compare/v0.2.0...v0.3.0) - 2026-08-07

**Joins. Every relation that can be joined, and three ways a join was returning the wrong rows.**

Soft deletes now apply to joined relations, not just the root model. Laravel applies no global
scope to a join, so a plan reading `lines.product.name` could read a product deleted a year ago.
The condition goes on the `ON` clause, so a left join stays a left join and a parent whose only
child was deleted still appears.

Every joined table is aliased to its relation path. Two paths reaching the same table used to
compile to two unaliased joins of it, which the database rejects outright as an ambiguous column
reference.

`belongsToMany`, `hasOneThrough`, `hasManyThrough`, `morphOne`, `morphMany` and `morphToMany` can
now be traversed. Each compiles to the joins it needs — a pivot or an intermediate table where
there is one — and counts as a single relation against `maxRelationDepth`. Polymorphic joins carry
their type condition, so a table shared by several parent types no longer hands one of them
another's rows.

Pivot columns are addressable under a reserved `pivot` segment: `tags.pivot.assigned_at` is the
link, `tags.name` is the thing it points at. `alwaysPivotScope()` constrains the link itself.

### Breaking

- A relation's `alwaysScope()` closure now receives the join alias as a third argument, and must
  qualify columns with it rather than with the table name. Joined tables are aliased to their
  relation path, so `products.type` no longer resolves where `lines__product.type` does.
  Resource-level `alwaysScope()` is unaffected — the root table is not aliased.
- Soft-deleted rows are excluded from joined relations. Reports that silently included them will
  return different numbers. `withTrashed()` on a relation restores the old behaviour where it was
  wanted.
- `morphTo` raises `CompilationException` instead of compiling into a join against whatever table
  the relation happened to resolve to. It cannot be joined: the table it points at is stored per
  row rather than fixed by the schema.
- `morphOne` and `morphMany` now constrain the join by morph type. A schema declaring one was
  previously returning rows belonging to every other parent type sharing that table.
- `pivot` is reserved as a relation name and raises `SchemaDefinitionException` if declared.

### Requires

PHP 8.3+, Laravel 13.16+

## [v0.2.0](https://github.com/j-t-mcc/ai-query-builder/compare/v0.1.1...v0.2.0) - 2026-08-07

**Two things: what a plan is allowed to say, and what it costs to say it.**

A filter value is now checked against the kind of thing its column holds. Previously only
`enum()` constrained a value, so `started_at >= "now-30d"` validated, compiled, bound and ran —
matching every row on MySQL and none on SQLite, with the agent reporting the result as the
answer. Types come from the model's casts, or `->typed()` where the casts don't say.

Agents no longer compute dates. A date column permitting `between` also accepts `within` with a
named range — `last_30_days`, `last_month`, `year_to_date`, and
`last_<N>_<seconds|minutes|hours|days|weeks|months|years>`. The window stays in the plan and
resolves on each run, so a stored plan keeps meaning what it said rather than freezing the day it
was written.

The contract also got cheaper to send. `ai-query:describe --cost` measures it,
`PlanSchemaDetail::Generic` stops the plan schema growing with the resource schema, and
`AiQueryBuilder::tools()` replaces one tool per resource with two. The README now documents prompt
caching, which outranks all of it.

### Breaking

- Resource names are validated at registration. Anything outside `^[a-zA-Z0-9_-]{1,128}$` throws
  `SchemaDefinitionException`, rather than reaching a provider as a 400.
- `QueryDataTool::name()` is now `query_{resource}` instead of the class basename, so two tools on
  one agent no longer collide. This changes the tool name a provider sees.
- The plan schema no longer carries a `resource` property. The tool sets it, so a plan cannot
  redirect a tool to another resource.
- A filter value of the wrong type is rejected with `value_type_mismatch`. A plan that previously
  ran with a malformed value now fails with a correctable error.
- `SchemaContract::toArray()` gained a `type` key and `filters` includes `within`, so
  `fingerprint()` values change once.

### Requires

PHP 8.3+, Laravel 13.16+

## [v0.1.1](https://github.com/j-t-mcc/ai-query-builder/compare/v0.1.0...v0.1.1) - 2026-08-06

Corrects the Composer vendor prefix. No functional changes.

The package was first published as `j-t-mcc/ai-query-builder`, which did not match
the `jtmcc/` prefix used by this author's other packages. Packagist cannot rename a
package, so the manifest was corrected and the package re-submitted.

### Installing

```bash
composer require jtmcc/ai-query-builder



```
**v0.1.0 is not installable.** Packagist matches the package name in `composer.json`
at each tag, and that tag carries the old vendor. Start from v0.1.1.

Everything else is unchanged from v0.1.0: PHP 8.3+, Laravel 13.16+, and the same
API surface.

## [v0.1.0](https://github.com/j-t-mcc/ai-query-builder/compare/v0.1.0...v0.1.0) - 2026-08-05

Initial pre-release. The API is expected to change before v1.

A safe boundary between an AI agent and your database. You declare exactly what an
agent may select, filter, join, group and sort. It emits a query plan — never SQL.
The package validates every token in that plan against your declaration, compiles it
to an Eloquent query with scopes the plan cannot express, and runs it under explicit
limits.

### What's included

- **Schema layer** — deny-by-default resource definitions. A declared column is
  selectable and nothing else until filtering, sorting, grouping or aggregation is
  granted explicitly. Per-user column visibility, relation traversal, and mandatory
  scopes no plan can remove.
- **Validation** — fails closed. Unknown keys are rejected rather than dropped,
  errors accumulate with machine-readable codes and did-you-mean suggestions, and a
  column hidden from a user is reported as unknown so a rejection cannot confirm it
  exists.
- **Compilation** — joins derived from declared Eloquent relations. Agent filters are
  wrapped in their own group so a top-level `or` cannot escape a tenant scope.
  Identifiers come from the schema; values are always bound.
- **Execution** — `QueryRunner` with row caps, explicit truncation flags, statement
  timeouts, read-only connection support, and `explain()` for approval flows.
- **Events** — `QueryPlanValidated`, `QueryPlanRejected`, `QueryPlanExecuted`. The
  executed event is the audit record; the package persists nothing.
- **AI integration** — `QueryDataTool` for the Laravel AI SDK, plus `SchemaContract`
  for any other layer. `laravel/ai` is a suggested dependency, not a required one.
- **Commands** — `ai-query:make-schema` scaffolds a draft with every column commented
  out and secrets omitted entirely; `ai-query:describe` prints the contract an agent
  actually receives, optionally as a given user.
- **HTTP endpoint** — opt-in, off by default.

### Known limits

- Aggregating a parent column across a to-many join is refused rather than answered
  with a silently inflated number. Aggregate on the joined side instead.
- No unions, no pagination, no cross-resource joins, no `BelongsToMany` traversal.
- Statement timeouts require pgsql, mysql or mariadb; other drivers raise rather than
  ignoring the setting.
- Eloquent global scopes are not applied to joined models — use a relation's
  `alwaysScope()`.

### Requirements

PHP 8.3+ and Laravel 13.16+.
