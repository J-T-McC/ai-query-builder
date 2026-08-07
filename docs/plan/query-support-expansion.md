# Plan — Wider query support

**Status:** implemented. Step 4 (`HasManyThrough`, polymorphic support) remains open.
**Scope:** `PlanCompiler`, `CompilationContext`, `RelationDefinition`, and the validator's relation rules.
**Related:** [architecture.md](architecture.md).

Two gaps in what the compiler can join today:

1. A joined table ignores soft deletes, so deleted rows come back.
2. Only `HasOne`, `HasMany` and `BelongsTo` can be joined. `BelongsToMany` throws, and there is no
   way to reach a pivot column.

Part A is small and self-contained. Part B needs one refactor first.

---

## Part A — Soft deletes on joins

**Implemented.** Landed as described below, with one adjustment: the workbench's `InvoiceLine`
fixture was given a non-default `DELETED_AT` of `archived_at`, so the suite proves the column is
read from the model rather than assumed. `Product` was left without the trait, so one join in the
tests carries no condition at all.

### The problem

The root model is fine. `PlanCompiler::compile()` starts from `$model::query()`, which applies the
model's global scopes, and `SoftDeletingScope` is one of them. Deleted invoices are already
excluded.

Joined tables are not fine. `applyJoins()` takes the relation object only to read its key names,
then calls `$query->leftJoin(...)` with the raw table name. A `leftJoin` does not run the related
model's global scopes — Laravel has no way to apply them to a join. So a plan that reads
`lines.product.name` will happily read a product that was deleted last year.

`RelationDefinition` already says this out loud:

> Eloquent global scopes on the related model are NOT applied to a join, so anything that must
> hold for joined rows belongs here.

Right now the only fix is for every schema author to write the same `alwaysScope` by hand on every
relation. Most will not, and the ones who forget get wrong numbers rather than an error.

### The fix

In `applyJoins()`, ask the related model whether it soft deletes, and if it does add
`deleted_at is null` to the join's `ON` clause:

```php
$related = $relation->getRelated();

$query->leftJoin($related->getTable(), function (JoinClause $join) use (...): void {
    $join->on($first, '=', $second);

    if ($deletedAt = $this->deletedAtColumn($related)) {
        $join->whereNull($deletedAt);
    }

    foreach ($scopes as $scope) {
        $scope($join, $user);
    }
});
```

Detection is one method. Checking for the accessor is enough, and it also covers a model that uses
a custom `DELETED_AT`:

```php
/**
 * The qualified deleted-at column of a soft-deleting model, or null.
 *
 * Detected from the accessor the SoftDeletes trait adds, so a model that
 * renames its column through DELETED_AT is handled without special cases.
 */
private function deletedAtColumn(Model $model): ?string
{
    return method_exists($model, 'getQualifiedDeletedAtColumn')
        ? $model->getQualifiedDeletedAtColumn()
        : null;
}
```

### Why the `ON` clause and not `WHERE`

This matters and it is easy to get wrong. Put `deleted_at is null` in the `WHERE` clause and every
left join turns into an inner join: an invoice whose only line was deleted disappears from the
result entirely. Put it in the `ON` clause and the invoice stays, with nulls where the deleted line
would have been. The second one is what a developer means by "ignore deleted rows".

The existing relation `alwaysScope` already takes this position, so this follows the rule the file
already sets.

### The escape hatch

Sometimes you do want the deleted rows — an audit resource, or a report on cancellations. Add one
method to `RelationDefinition`:

```php
$schema->relation('lines', fn ($lines) => $lines->withTrashed());
```

```php
/**
 * Include soft-deleted rows from this relation.
 *
 * Off by default: a deleted row reaching an aggregate is a wrong answer that
 * looks like a right one. Turning it on is a deliberate choice by the schema
 * author and cannot be expressed by a plan.
 */
public function withTrashed(bool $include = true): self
{
    $this->withTrashed = $include;

    return $this;
}
```

Suggestion: no `onlyTrashed()`. If someone needs it, they can declare `deleted_at` as a column with
the `is_not_null` operator and let the agent filter on it, which is more honest about what is going
on.

### What the agent sees

Nothing. This is invisible policy, exactly like `alwaysScope`. No contract change, no JSON Schema
change, no extra tokens, and the schema bytes stay stable for prompt caching.

### Edge cases worth a test

- Root model soft deletes → already excluded, and the join fix must not add a second condition.
- Parent kept when all its children are deleted (proves `ON`, not `WHERE`).
- Relation with `withTrashed()` → deleted children come back.
- Model with a custom `DELETED_AT` constant → the custom column is used.
- Relation with both a soft delete and an `alwaysScope` → both land on the `ON` clause.
- Nested path `lines.product` where only `product` soft deletes.

### A note on the change being breaking

Anyone whose reports currently include deleted joined rows will see their numbers change. The old
numbers were wrong, but the change is still visible, so it belongs in a minor version with a clear
changelog line rather than a patch.

---

## Part B — Belongs-to-many and pivot tables

### Step 0 — Give every join an alias (prerequisite)

**Implemented.** One thing the proposal below understated: aliasing changes the name a relation
`alwaysScope` must use, so it is a breaking change for any schema whose relation scope hardcodes a
table name. The closure now receives the alias as a third argument. Confirmed the bug was real
before the fix — two paths to `products` failed with `ambiguous column name: products.name`.

`CompilationContext::$tables` maps a relation path to a **table name**, and joins are added with no
alias. That works only while every path in a plan reaches a different table. It already breaks
today on a schema like `author.company` plus `publisher.company`: two joins of `companies`, no
aliases, ambiguous SQL.

A belongs-to-many join makes this certain rather than possible, because it adds a second table
(the pivot) per relation, and the same pivot table can be reached by more than one path.

The fix is to alias every join from its relation path:

```php
// lines.product → "lines__product"
$alias = str_replace('.', '__', $path);

$query->leftJoin("{$table} as {$alias}", ...);
```

Two follow-on changes:

- `CompilationContext::$tables` becomes a path → alias map. `qualify()` needs no change at all,
  since it already builds `"{$tables[$path]}.{$column->name()}"`.
- The `ON` clause can no longer use `getQualifiedForeignKeyName()`, because those return the real
  table name. Build each side from the alias plus the unqualified key name:

  | Relation | Left side | Right side |
  |---|---|---|
  | `BelongsTo` | `{$parentAlias}.{$relation->getForeignKeyName()}` | `{$alias}.{$relation->getOwnerKeyName()}` |
  | `HasOneOrMany` | `{$parentAlias}.{$relation->getLocalKeyName()}` | `{$alias}.{$relation->getForeignKeyName()}` |

  The root alias is the root table name, so nothing changes for un-joined columns.

Aliases are derived from the path, so they are deterministic — the same plan compiles to the same
SQL every time, which keeps query-log diffing and any statement cache working.

This step is worth doing on its own even if Part B stops here. It is a bug fix with tests of its
own: two relations to the same table, and a self-referencing relation such as
`categories.parent.name`.

### Step 1 — Join through the pivot

**Implemented**, along with step 2's `alwaysPivotScope`, exactly as described.

One thing this proposal got wrong. It planned to reject `MorphToMany` because it extends
`BelongsToMany` and would otherwise slip through an `instanceof` check. True — but the same trap
was **already sprung** on the other side: `MorphOneOrMany extends HasOneOrMany`, so a declared
`morphMany` or `morphOne` was being joined *today*, without its `*_type` condition, silently
matching rows of every parent type sharing that table. That is the exact failure this plan called
"worse than an error", and it was already live.

Both families are now refused through `CompilationException::unsupportedPolymorphicRelation()`.
Supporting them properly stays a follow-up: the condition is one `where` on the morph type, but it
wants its own polymorphic fixtures to test against rather than being bolted onto this change.

A `BelongsToMany` is two joins, not one. Everything needed is on the relation object:

```php
$relation instanceof BelongsToMany => [
    'pivotTable'  => $relation->getTable(),                     // the pivot table
    'parentKey'   => $relation->getParentKeyName(),             // e.g. users.id
    'foreignPivot'=> $relation->getForeignPivotKeyName(),       // e.g. role_user.user_id
    'relatedPivot'=> $relation->getRelatedPivotKeyName(),       // e.g. role_user.role_id
    'relatedKey'  => $relation->getRelatedKeyName(),            // e.g. roles.id
],
```

Which compiles to, for path `roles`:

```sql
left join "role_user" as "roles__pivot"
       on "users"."id" = "roles__pivot"."user_id"
left join "roles" as "roles"
       on "roles__pivot"."role_id" = "roles"."id"
```

The pivot alias is the relation path plus a fixed `__pivot` suffix, so it never collides with a
relation alias (a relation segment cannot contain `__pivot` because it is a PHP method name — worth
a guard in `RelationDefinition` anyway).

Three things fall out for free:

- **Fan-out is already handled.** A belongs-to-many always multiplies parent rows, so register the
  path in `CompilationContext::$toMany`. `guardAgainstFanOut()` then refuses `sum(users.salary)`
  across a `roles` join with the error it already writes, and no new rule is needed.
- **Nesting still works.** The parent model for the next path segment is `$relation->getRelated()`,
  same as every other relation, so `roles.permissions.name` needs no special handling.
- **Soft deletes still work.** Part A applies to the related-table join without change.

### Step 2 — Scopes on which join?

`RelationDefinition::alwaysScope()` currently receives the one `JoinClause`. With two joins, the
answer should be: **`alwaysScope` applies to the related-table join**, because that is what schema
authors mean when they write it today, and existing schemas keep working.

For the pivot join, add a second method:

```php
$schema->relation('roles', fn ($roles) => $roles
    ->alwaysScope(fn (JoinClause $join) => $join->where('roles.active', true))
    ->alwaysPivotScope(fn (JoinClause $join) => $join->whereNull('role_user.revoked_at')));
```

Most schemas will never need `alwaysPivotScope`, but without it there is no way to constrain the
link itself, which is where things like `revoked_at` and `is_primary` live.

### Step 3 — Pivot columns

**Implemented as Option A.** Two things worth recording.

The pivot is a `PivotDefinition`, not a `RelationDefinition`, because nothing is traversable
through an intermediate table. That meant splitting `DefinesStructure` into `DeclaresColumns`
(columns only, which a pivot needs) and `DefinesStructure` (columns plus relations and traversal).

Types are not inferred on a pivot. `ColumnTypeResolver` reads casts off the Eloquent model a path
resolves to, and an intermediate table usually has no model. It fails open, so an undeclared pivot
column simply gets no type and no checking — declare it with `typed()`. Doing so is worth more than
it looks: a declared `date` earns the column `within` and filter-value checking on the same terms
as a root column.

Measured cost on the fixture schema: one declared pivot column adds 124 bytes to the contract
(prompt plus JSON Schema), of which the `pivot.` segment is 6. The disambiguation Option A buys
costs roughly two tokens per pivot column.


Reading `roles.name` works after step 1. Reading *when* the role was assigned does not, because
`assigned_at` lives on the pivot table and the pivot table is not a node in the schema tree.

Two ways to expose it. Both are small; they differ in how the path reads.

**Option A — a `pivot` sub-node (suggested).**

```php
$schema->relation('roles', fn ($roles) => $roles
    ->column('name')
    ->pivot(fn ($pivot) => $pivot
        ->column('assigned_at', fn ($c) => $c->filterable(Operator::Between))));
```

Paths read `roles.pivot.assigned_at`. Pros: no chance of a pivot column shadowing a real one, and
it is obvious to the agent and to anyone reading the schema which table a value comes from.
Cons: `traverse()` in `DefinesStructure` needs to know `pivot` is a node, and the path is one
segment longer, which costs a few tokens per pivot column in the contract.

**Option B — pivot columns declared inline.**

```php
$schema->relation('roles', fn ($roles) => $roles
    ->column('name')
    ->pivotColumn('assigned_at'));
```

Paths read `roles.assigned_at`. Shorter, and no traversal change — the relation keeps one column
map and records which entries came from the pivot. Cons: `roles.assigned_at` gives no hint that it
is a link attribute, and a name on both tables needs a collision error at schema-definition time.

**Decided: Option A.** This package's whole posture is that a path should mean exactly one thing,
and the extra segment buys that at a cost of a handful of tokens.

Either way, `qualify()` needs to resolve a pivot path to the pivot alias. With Option A that is a
one-line change, since `roles.pivot` is already its own key in the `tables` map.

### Step 4 — What to reject for now, and say so clearly

- **`MorphToMany`** extends `BelongsToMany`, so it would slip through an `instanceof` check and
  silently join rows of every morph type. Reject it explicitly. Supporting it later is small — add
  `$join->where($morphType, $relation->getMorphClass())` to the pivot join — but a wrong-by-default
  join is worse than an error.
- **`HasManyThrough`** is the same two-join machinery with different key names. Natural next step
  once the aliasing work is in, and no new concepts.
- **`MorphTo`** cannot be joined at all — the target table is not knowable until the rows are read.
  It should stay a compile error.

`CompilationException::unsupportedRelation()` already names the supported list; update its message
as each of these lands.

### Depth and cost

A belongs-to-many counts as **one** relation for `maxRelationDepth`, even though it is two joins.
The pivot node does not count either. Depth is about how far an agent can reach, not about how many
joins the compiler writes. Worth a comment in the validator so nobody "fixes" it later.

That said, the default `maxRelationDepth` of 2 now permits noticeably more expensive SQL than it
did — `a.b.c` where both hops are many-to-many is six joins. No change suggested, but it belongs in
the README's note on limits.

### Tests worth naming

- `roles.name` compiles to two joins with the expected aliases.
- `sum(users.salary)` across a `roles` join is refused by the existing fan-out guard.
- `count(roles.id)` grouped by a user column returns the right count per user.
- `roles.pivot.assigned_at` filters on the pivot table, not the related table.
- `alwaysPivotScope` lands on the pivot join and `alwaysScope` on the related join.
- A soft-deleting related model still gets `deleted_at is null` on its own join.
- A `MorphToMany` relation throws with a message naming the relation type.
- Two paths reaching the same table compile to two distinct aliases.

---

## Suggested order

| Phase | Work | Why here |
|---|---|---|
| 1 | ~~Soft deletes on joins + `withTrashed()`~~ **done** | Self-contained, fixes wrong results, no contract change |
| 2 | ~~Alias every join~~ **done** | Bug fix on its own, and a hard prerequisite for phase 3 |
| 3 | ~~`BelongsToMany` joins, `alwaysPivotScope`, reject `MorphToMany`~~ **done** | The main feature |
| 4 | ~~Pivot columns via a `pivot` node~~ **done** | Needs phase 3; the only piece that changes the contract |
| 5 | Optional: `HasManyThrough`, then the polymorphic relations | Same machinery, no new concepts |

Phases 1 and 2 are independent of each other and can ship in either order. Phase 4 is the only one
that changes what an agent sees, so it is the only one that needs a look at token cost and at the
Boost skill.