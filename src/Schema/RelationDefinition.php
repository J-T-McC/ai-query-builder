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

    private bool $withTrashed = false;

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
     * @param  Closure(JoinClause, Authenticatable|null): mixed  $scope
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

    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }
}
