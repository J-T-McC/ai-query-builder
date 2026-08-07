<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Schema;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\JoinClause;
use JTMcC\AiQueryBuilder\Schema\Concerns\DefinesStructure;

/**
 * A traversable Eloquent relation and the columns it exposes.
 *
 * The relation name is the method on the parent model. The compiler derives the
 * join from that relation, so an agent can never express a join condition itself.
 */
final class RelationDefinition
{
    use DefinesStructure;

    private ?string $description = null;

    /** @var list<Closure> */
    private array $alwaysScopes = [];

    /** @var list<Closure> */
    private array $alwaysPivotScopes = [];

    private bool $withTrashed = false;

    private ?PivotDefinition $pivot = null;

    public function __construct(private readonly string $name) {}

    public function describe(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * A condition added to this relation's JOIN clause on every compiled query.
     *
     * Applied as an ON condition rather than a WHERE so it constrains the join
     * without turning a left join into an inner one. Eloquent global scopes on
     * the related model are NOT applied to a join, so anything that must hold
     * for joined rows belongs here.
     *
     * The joined table is aliased to its relation path, so qualify columns with
     * the third argument rather than with the table name. `lines.product` is
     * joined as `lines__product`, and `lines__product.type` is the only name
     * that resolves.
     *
     * @param  Closure(JoinClause, Authenticatable|null, string): mixed  $scope
     */
    public function alwaysScope(Closure $scope): self
    {
        $this->alwaysScopes[] = $scope;

        return $this;
    }

    /** @return list<Closure> */
    public function alwaysScopes(): array
    {
        return $this->alwaysScopes;
    }

    /**
     * A condition added to the pivot join of a many-to-many relation.
     *
     * Constrains the link rather than the row it points at, which is where
     * attributes like `revoked_at` and `is_primary` live. Ignored by every
     * other relation type, since they have no pivot join.
     *
     * The pivot is aliased to the relation path plus `__pivot`, so qualify
     * columns with the third argument rather than the table name.
     *
     * @param  Closure(JoinClause, Authenticatable|null, string): mixed  $scope
     */
    public function alwaysPivotScope(Closure $scope): self
    {
        $this->alwaysPivotScopes[] = $scope;

        return $this;
    }

    /** @return list<Closure> */
    public function alwaysPivotScopes(): array
    {
        return $this->alwaysPivotScopes;
    }

    /**
     * Include soft-deleted rows from this relation.
     *
     * Excluded by default. A join does not run the related model's global
     * scopes, so without this the compiler applying the deleted-at condition
     * itself is the only thing keeping deleted rows out of an aggregate — and a
     * deleted row reaching an aggregate is a wrong answer that looks like a
     * right one. Including them is a deliberate choice by the schema author and
     * cannot be expressed by a plan.
     */
    public function withTrashed(bool $include = true): self
    {
        $this->withTrashed = $include;

        return $this;
    }

    public function includesTrashed(): bool
    {
        return $this->withTrashed;
    }

    /**
     * Declare columns on the intermediate table of a many-to-many relation.
     *
     * Addressed under a `pivot` segment — `tags.pivot.assigned_at` — so a pivot
     * column and a related column of the same name stay distinguishable.
     *
     * @param  Closure(PivotDefinition): mixed  $configure
     */
    public function pivot(Closure $configure): self
    {
        $configure($this->pivot ??= new PivotDefinition);

        return $this;
    }

    /**
     * The pivot node, or null when the relation declares no pivot columns.
     */
    public function pivotDefinition(): ?PivotDefinition
    {
        return $this->pivot;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }
}
