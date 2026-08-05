# AI Query Builder — Architecture Plan

**Status:** draft for review
**Package:** `j-t-mcc/ai-query-builder`

## 1. The core idea

The package is **not an AI layer**. It is a *sandbox* that sits between an AI layer you already
have and your database.

```
user prompt ──▶ [ your AI layer ] ──▶ QueryPlan (untrusted JSON)
                        ▲                      │
                        │                      ▼
                  contract (JSON             VALIDATE ──✗──▶ structured errors (retryable)
                  Schema + data                  │
                  dictionary)                    ✓
                        ▲                      ▼
                        └──────────────  COMPILE (+ mandatory scopes)
                                                │
                                                ▼
                                          EXECUTE (guarded) ──▶ rows
```

Three non-negotiable properties:

1. **The AI never emits SQL, column names, or table names of its own invention.** It emits a
   plan referencing *tokens we published to it*. Anything not in the allow-list is rejected.
2. **Plan generation is data-blind.** Building a plan requires only the schema contract. The AI
   touches no rows until a validated plan executes.
3. **Mandatory scopes are not expressible in the plan.** They are applied by the compiler, after
   validation, and there is no plan shape that can remove them.

## 2. Layers

| Layer | Responsibility | Trusts the AI? |
|---|---|---|
| `Schema` | Developer declares what is exposed. Fluent, deny-by-default. | n/a |
| `Contract` | Renders the schema as JSON Schema + a data dictionary for the prompt. | n/a |
| `Plan` | Value object for the untrusted IR. | no |
| `Validation` | Every token checked against the schema. Fail closed. | **security boundary** |
| `Compilation` | Plan → Eloquent builder. Applies mandatory scopes. | no |
| `Execution` | Row caps, timeouts, read-only connection, audit. | no |
| `Ai` | Optional adapters (Laravel AI SDK tool / structured output). | n/a |

Everything above `Ai` works with a plain `array`. That is what makes it drop-in on top of an
existing AI layer.

## 3. Schema definition (deny by default)

```php
namespace App\AiQueries;

use JTMcC\AiQueryBuilder\Schema\ResourceSchema;
use JTMcC\AiQueryBuilder\Schema\Contracts\DefinesQuerySchema;

class InvoiceQuerySchema implements DefinesQuerySchema
{
    public function define(ResourceSchema $schema): ResourceSchema
    {
        return $schema
            ->for(Invoice::class)
            ->name('invoices')
            ->describe('Customer invoices, one row per invoice.')

            // ---- selectable columns -------------------------------------------------
            ->column('id', fn (ColumnDefinition $c) => $c
                ->as('invoice_id')
                ->describe('Invoice primary key'))
            ->column('issued_at', fn (ColumnDefinition $c) => $c
                ->describe('Date the invoice was issued')
                ->filterable(['=', '>', '<', '>=', '<=', 'between'])
                ->groupableBy(['day', 'week', 'month', 'quarter', 'year']) // date bucketing
                ->sortable())
            ->column('total', fn (ColumnDefinition $c) => $c
                ->describe('Invoice total')
                ->measuredIn('currency:USD')
                ->aggregatable(['sum', 'avg', 'min', 'max'])  // per-column function allow-list
                ->filterable(['>', '<', 'between'])
                ->sortable())
            ->column('status', fn (ColumnDefinition $c) => $c
                ->enum(['draft', 'sent', 'paid', 'void'])     // AI cannot probe for values
                ->filterable(['=', 'in'])
                ->groupable())

            // filterable but NOT selectable — can slice by it, can never see it
            ->column('internal_margin', fn (ColumnDefinition $c) => $c
                ->filterable(['>', '<'])
                ->selectable(false))

            // gated per-user: if the closure is false, the column is absent from the
            // contract entirely. The AI never learns it exists.
            ->column('customer_notes', fn (ColumnDefinition $c) => $c
                ->visibleWhen(fn (?Authenticatable $user) => $user?->can('viewInvoiceNotes') ?? false))

            // ---- traversal ----------------------------------------------------------
            // Named relation paths only. Never raw joins, never user-supplied ON clauses.
            ->relation('lines', fn (RelationDefinition $r) => $r
                ->describe('Line items on the invoice')
                ->column('quantity', fn (ColumnDefinition $c) => $c->aggregatable(['sum']))
                ->relation('product', fn (RelationDefinition $p) => $p
                    ->column('name', fn (ColumnDefinition $c) => $c->filterable(['=', 'like'])->groupable())
                    ->column('type', fn (ColumnDefinition $c) => $c
                        ->enum(['widget', 'service'])
                        ->filterable(['=', 'in'])
                        ->groupable())))
            ->maxRelationDepth(2)

            // ---- non-bypassable policy ---------------------------------------------
            ->alwaysScope(fn ($query, $user) => $query->where('invoices.tenant_id', $user->tenant_id))
            ->alwaysScope(fn ($query, $user) => $query->whereNull('invoices.deleted_at'))

            // ---- limits -------------------------------------------------------------
            ->defaultLimit(100)
            ->maxLimit(1000)
            ->maxGroups(500);
    }
}
```

Registered in config:

```php
// config/ai-query-builder.php
'resources' => [
    App\AiQueries\InvoiceQuerySchema::class,
],
```

**Design rule:** every builder method is additive-permissive. A column that is declared but never
given `->filterable()` cannot be filtered. A column never declared does not exist.

## 4. The Query Plan (IR)

`show me the sum of all invoices between date 1 and date 2, but only for this product type`
becomes:

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
  "having": [{ "column": "total_qty", "operator": ">", "value": 0 }],
  "sort": [{ "column": "total_qty", "direction": "desc" }],
  "limit": 50
}
```

Notes:

- Columns are **relation paths**, not SQL. `lines.product.type` implies the join; the compiler
  derives it from the declared relations. The AI cannot express a join.
- `value` is always data, always bound as a parameter, never interpolated.
- There is no `raw`, no `expression`, no `sql` key. Not even behind a config flag.

### The fan-out trap

An earlier draft of this example aggregated `total` — the *invoice* total — while joining to
`lines`. That is wrong, and wrong in the most dangerous way: it returns a plausible number.

An invoice with three line items appears three times once joined, so `SUM(invoices.total)` counts
it three times. Nothing errors. The agent reports a confident, inflated figure.

The compiler refuses to build it (§6.1). The example above aggregates `lines.quantity` instead,
which sits on the same side of the join as the fan-out and is therefore counted once per row.

This also affects the phrasing that motivated the package — *"the sum of all invoices … only for
this product type"*. Summing invoice totals while **filtering** on a to-many relation fans out for
exactly the same reason. Compiling relation-only filters to an `EXISTS` subquery instead of a join
solves it properly and means what the question actually means (*invoices having at least one
widget line*). That is in the v2 backlog; until then the compiler rejects the plan rather than
answering it wrongly.

## 5. Validation — the security boundary

```php
final class PlanValidator
{
    public function validate(array $input, ResourceSchema $schema, ?Authenticatable $user): QueryPlan
    {
        $errors = [];

        // Unknown top-level keys are an error, not ignored. Silent dropping produces a
        // confidently wrong answer, which is its own failure mode.
        $this->rejectUnknownKeys($input, self::ALLOWED_KEYS, $errors);

        $contract = $schema->contractFor($user);   // per-user: gated columns already removed

        foreach ($input['select'] ?? [] as $i => $select) {
            $column = $contract->column($select['column'] ?? null)
                ?? $errors[] = ValidationError::unknownColumn(
                       path: "select.$i.column",
                       given: $select['column'] ?? null,
                       didYouMean: $contract->closestColumn($select['column'] ?? ''),
                   );

            if (isset($select['function']) && ! $column?->allowsAggregate($select['function'])) {
                $errors[] = ValidationError::functionNotAllowed("select.$i.function", ...);
            }
        }

        // ... filters (recursive, depth-capped), group_by, having, sort, limit

        // Semantic checks the database would only catch at runtime:
        $this->assertEveryNonAggregatedSelectIsGrouped($input, $errors);
        $this->assertHavingReferencesAnAggregateAlias($input, $errors);
        $this->assertRelationDepthWithinLimit($input, $schema, $errors);

        if ($errors) {
            throw new InvalidQueryPlanException($errors);   // structured, retryable
        }

        return QueryPlan::fromValidated($input, $contract);
    }
}
```

`InvalidQueryPlanException` carries machine-readable errors so the AI layer can retry with a
bounded number of attempts:

```json
{ "path": "select.1.column", "code": "unknown_column",
  "message": "Unknown column 'invoice_total'.", "did_you_mean": "total" }
```

## 6. Compilation — where scopes become non-bypassable

This is the part most likely to be got wrong, so it is worth stating explicitly.

```php
final class PlanCompiler
{
    public function compile(QueryPlan $plan, ResourceSchema $schema, ?Authenticatable $user): Builder
    {
        $query = $schema->model()::query();

        // 1. Mandatory scopes FIRST, at the outermost level.
        foreach ($schema->alwaysScopes() as $scope) {
            $scope($query, $user);
        }

        // 2. The AI's filter tree is wrapped in its own group. Without this nesting an
        //    OR at the top of the AI tree would escape the tenant scope:
        //        WHERE tenant_id = 1 AND ai_a OR ai_b        ← broken
        //        WHERE tenant_id = 1 AND (ai_a OR ai_b)      ← correct
        if ($plan->filters) {
            $query->where(fn ($q) => $this->applyFilterTree($q, $plan->filters));
        }

        // 3. Joins come only from declared relation paths, and each joined resource
        //    contributes ITS own mandatory scopes too.
        foreach ($plan->relationPaths() as $path) {
            $this->joinDeclaredRelation($query, $schema, $path, $user);
        }

        // 4. Select / group / having / sort — all identifiers come from the schema
        //    object, never from the plan's raw strings.
        $this->applySelects($query, $plan, $schema);
        // ...

        // The limit is already validated against maxLimit; an over-max limit is
        // rejected rather than clamped, so a plan never quietly returns fewer
        // rows than it asked for. Re-applying the ceiling here is defence in depth.
        $query->limit(min($plan->limit, $schema->limits()->max));

        return $query;
    }
}
```

## 6.1 Compilation decisions

- **Left joins.** A parent with no related rows is kept. A relation's own `alwaysScope` is applied
  as an `ON` condition rather than a `WHERE`, which would silently turn it into an inner join.
- **Eloquent global scopes do not apply to joins.** A `SoftDeletes` scope on a *joined* model is
  not applied by the database — Laravel only adds it to the root query. Anything that must hold
  for joined rows belongs in that relation's `alwaysScope`. This is a sharp edge worth documenting
  for users.
- **Fan-out guard.** Aggregating a column that sits above a to-many join is refused
  (`CompilationException`), because the result would be silently multiplied. See §4.
- **`having` operators are restricted** to comparisons, `between` and null checks. Set membership
  and pattern matching are not meaningful against an aggregate, and supporting them would mean
  generating raw SQL for the having clause.
- **`SqlFragment`.** Laravel types its raw-SQL entry points as `literal-string` to stop
  applications building SQL from runtime data. A query compiler cannot satisfy that, so the
  package owns a single `Expression` implementation and every generated fragment goes through it.
  The invariant: fragments are built only from schema-declared identifiers wrapped by the
  connection grammar; plan values are always bound.

## 7. Execution guardrails

`QueryRunner` is resolved from the container, so it picks up configured defaults. Its setters
clone, so a configured runner never leaks settings into the next call.

```php
$result = app(QueryRunner::class)
    ->as($user)
    ->connection('analytics_readonly')  // opt-in read-only connection
    ->timeout(5_000)                    // statement timeout, milliseconds
    ->maxRows(1_000)
    ->withPrompt($prompt)               // carried into the audit events
    ->run($plan);                       // $plan is the untrusted array
```

- **Truncation is explicit.** The runner fetches one row beyond the cap so a full result can be
  told apart from a capped one, and `ResultSet::$truncated` says which it was. A silently capped
  result reads as a complete answer.
- **Column metadata travels with the rows.** `ResultSet::$columns` carries each alias's unit and
  description, because a number without its unit is how a model reports cents as dollars.
- **Timeouts fail loud.** Only pgsql, mysql and mariadb can enforce a statement timeout. On any
  other driver a non-null timeout raises rather than being quietly ignored — otherwise the caller
  believes a guardrail is in place when it is not.
- **`->explain($plan)`** validates and compiles but does not execute, returning SQL and bindings
  for human-in-the-loop approval or logging.

### The audit record is an event

`QueryPlanExecuted` carries the plan, the SQL and bindings it became, the acting user, the
prompt, row count, duration and the truncation flag. The package does not persist it and ships no
migration: what to store, how long to keep it, and what to redact are decisions only the host
application can make.

`QueryPlanValidated` fires after validation and before compilation — that is the hook for an
approval gate. `QueryPlanRejected` carries the error codes (§5.1).

Not yet implemented, and still worth having: per-user rate limiting, and caching an identical
plan + user + scope.

## 8. AI integration — three doors, all optional

### Door 1: Laravel AI SDK tool (recommended)

`laravel/ai` is at **v0.10.2** — pre-1.0. It is a `suggest` and a `require-dev`, never a
`require`: the package works with no AI layer at all, and a 0.x dependency should not dictate
this package's stability.

`QueryDataTool` is shipped. The whole integration is registering it on an agent you already have:

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

- `description()` is the data dictionary for that user (§9's `toPrompt()`).
- `schema()` constrains the model at the decoding layer.
- `handle()` runs the plan and returns rows as JSON. A rejection is *returned* rather than
  thrown, so the model can correct it — which makes retries the agent loop's decision. Cap them
  with `#[MaxSteps(1)]` for strictly one-shot (§5.1).
- `resource` defaults into the plan, so the model does not have to repeat it.

**The tool schema nests filters to a bounded depth.** Laravel's `JsonSchema` builder has no
`$ref`, so a recursive filter tree cannot be expressed the way `SchemaContract::toJsonSchema()`
does it. `PlanToolSchema` inlines groups three levels deep instead, with the innermost accepting
only conditions. That bounds what a *model can emit*; it is unrelated to the schema's
`maxFilterDepth`, and the validator remains the authority either way.

### Door 2: structured output

For a single-shot "prompt in, rows out" endpoint, implement `Agent, HasStructuredOutput` in your
app and reuse the same schema builder — there is nothing for the package to add:

```php
public function schema(JsonSchema $schema): array
{
    return (new PlanToolSchema(
        SchemaContract::for(app(SchemaRegistry::class)->get('invoices'), auth()->user())
    ))->build($schema);
}
```

Then hand the structured response to `QueryRunner::run()`.

### Door 3: bring your own

```php
$plan = json_decode($whateverYourLlmReturned, true);
$rows = app(QueryRunner::class)->as($user)->run($plan);
```

Plus a publishable controller + route for the "just give me an endpoint" case, off by default.

## 9. Schema generation command

```
php artisan ai-query:make-schema Invoice
```

Introspects `Schema::getColumns()` / foreign keys / Eloquent relations and writes a **draft**:

- Every column scaffolded **commented out**, so opting in is a deliberate edit.
- Columns matching a sensitive-name denylist (`password`, `token`, `secret`, `ssn`, `*_hash`,
  `remember_token`) are omitted entirely, with a note.
- Aggregates suggested only for numeric columns, date bucketing only for date columns.
- A `TODO: alwaysScope` stub, because tenant scoping cannot be inferred.

`ai-query:describe {resource} --user=1` dumps the exact contract a user would see, as prompt
text or (`--json`) as JSON Schema. Reading a schema definition cannot tell you whether a gated
column is really hidden from someone; this can. Omit the resource to list what is registered.

`ai-query:try {resource} "natural language"` is not built — it needs an AI layer, which the
package deliberately does not have. The equivalent without one is `QueryRunner::explain()`.

### On the audit table

The skeleton's placeholder migration is gone, along with the `ai-query-builder-migrations`
publish tag: publishing a junk table into a consumer's app is worse than shipping nothing. A real
opt-in query-history table remains worth building — replay, abuse detection, failure-rate
analysis — but it needs deliberate answers on retention, indexing, whether rejected plans are
stored too, and redaction of bound values that may hold personal data. Guessing those is how a
package ends up with a table nobody trusts. Until then `QueryPlanExecuted` carries everything
such a table would.

## 10. Additional ideas worth considering

**Schema surface**
- Computed fields defined in PHP, requestable by name (`margin`) — safe expressions, no AI input.
- Column masking (return `****1234` rather than the raw value).
- Opt-in distinct-value provider per column so the AI can map "widgets" → `widget` (leaks data by
  design — off unless declared, cached).
- Unit/timezone metadata on the contract so the narrating model formats correctly. Timezone
  handling on date ranges is the single biggest source of quietly-wrong analytics answers.

**Scale**
- Two-stage flow for many resources: stage 1 picks the resource, stage 2 plans against only that
  schema. Avoids stuffing every schema into context.

**Trust**
- Returned rows are untrusted input to the next turn. Document that host apps must not let query
  results widen the schema or drive further tool selection unguarded.
- No raw-SQL escape hatch at any config level. Once one exists, every other guarantee is advisory.
- Rate limit per user; cap plan complexity (join count, filter tree nodes) as a cost proxy.

## 11. Build phases

| Phase | Deliverable |
|---|---|
| 1 | ✅ **Done.** `ResourceSchema` + column/relation definitions + registry. |
| 2 | ✅ **Done.** `QueryPlan` + `PlanValidator` with structured errors. |
| 3 | ✅ **Done.** `PlanCompiler` + mandatory scopes + the filter-nesting guarantee. |
| 4 | ✅ **Done.** `QueryRunner`, guardrails, events, audit. |
| 5 | ✅ **Done.** Contract layer + Laravel AI SDK tool adapter. |
| 6 | ✅ **Done.** Generator + describe commands. |
| 7 | Publishable endpoint, README, Boost skill regeneration. |

Phases 1–4 have no AI dependency at all and are fully testable with Testbench + workbench models.

## 12. Decisions

1. **Compile target: Eloquent.** Global scopes, casts, and relation metadata come for free, and
   `alwaysScope` closures receive a familiar builder. Aggregate-only plans still compile through
   Eloquent rather than dropping to the base builder — one code path is worth more than the
   marginal overhead.
2. **Joins in v1, via declared relation paths** (`lines.product.type`). Unions deferred to v2.
   See the caveat below on *unrelated-root* joins.
3. **One-shot validation. No retry loop in v1** — but the seam is designed in, not bolted on
   later. See §5.1.
4. **No pagination in v1.** `limit` + `maxLimit` only. Cursor pagination in v2.

### v2 backlog

- Unions / multi-resource plans
- Cursor pagination
- Opt-in bounded retries (§5.1)
- Declared join edges between unrelated roots (below)
- `EXISTS` subqueries for relation-only filters, so aggregating a parent column while filtering on
  a to-many relation stops being a fan-out (§4)
- `BelongsToMany` traversal — currently unsupported, and rejected explicitly rather than
  compiled into something subtly wrong

### Caveat on joins

Two different things are called "joins", and only one is in v1:

- **Relation-path joins** — traversal along a declared Eloquent relation
  (`invoices → lines → product`). In v1. The AI names a path; the compiler derives the join and
  applies the joined resource's own mandatory scopes.
- **Joins between unrelated roots** — e.g. invoices to support tickets where no Eloquent relation
  exists. **Not needed.** Confirmed out of scope; every traversal is derived from a declared
  Eloquent relation. If this is ever revisited it needs a `->joinEdge()` primitive declaring the
  ON condition explicitly, and it would change the schema layer, not the compiler.

## 5.1 Retry seam (deliberately unused in v1)

The goal is to measure the real failure rate before spending tokens on retries. Two pieces make
that possible without designing for retry now:

**Errors are already machine-readable.** `InvalidQueryPlanException` carries structured errors
(§5). Adding retry later is a wrapper around the adapter — no change to validator, compiler, or
schema.

**Every rejection is measured.** `QueryPlanRejected` carries the error codes, the offending
plan, and the resource, so failure rate is a metrics query rather than a code change:

```php
Event::listen(QueryPlanRejected::class, function ($event) {
    Log::channel('ai-queries')->info('rejected', [
        'resource' => $event->resource,
        'codes'    => $event->errorCodes(),   // ['unknown_column', ...]
    ]);
});
```

**One caveat specific to tool-calling (Door 1).** In an agent loop, returning an error *string*
from `handle()` is itself an implicit retry — the model sees the failure and may call the tool
again, bounded by `#[MaxSteps]`. So "one and done" has to be chosen explicitly per door:

| Door | v1 behaviour |
|---|---|
| 1 — AI SDK tool | Return structured errors to the model. Retries are then the host's call via `#[MaxSteps]`; document that `MaxSteps(1)` makes it strictly one-shot. |
| 2 — structured output | Throw. No loop. |
| 3 — bring your own | Throw. Host decides. |

Door 1 cannot be made strictly one-shot from inside the tool — the loop belongs to the agent — so
this is a documentation obligation, not a code one.
