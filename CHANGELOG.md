# Release Notes

## [Unreleased](https://github.com/j-t-mcc/ai-query-builder/compare/v0.1.1...HEAD)

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
