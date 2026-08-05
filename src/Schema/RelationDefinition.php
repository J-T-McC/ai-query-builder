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

    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }
}
