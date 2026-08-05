<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Schema;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use JTMcC\AiQueryBuilder\Exceptions\SchemaDefinitionException;
use JTMcC\AiQueryBuilder\Schema\Concerns\DefinesStructure;

/**
 * The allow-list for one queryable resource.
 *
 * Nothing is exposed until it is declared here. Everything an AI agent is
 * permitted to reference comes from this object, and mandatory scopes declared
 * here cannot be expressed — and therefore cannot be removed — by a query plan.
 */
final class ResourceSchema
{
    use DefinesStructure;

    /** @var class-string<Model>|null */
    private ?string $model = null;

    private ?string $resourceName = null;

    private ?string $description = null;

    /** @var list<Closure> */
    private array $alwaysScopes = [];

    private int $defaultLimit = 100;

    private int $maxLimit = 1000;

    private int $maxGroups = 500;

    private int $maxRelationDepth = 2;

    public static function make(): self
    {
        return new self;
    }

    /**
     * Bind the resource to an Eloquent model.
     *
     * @param  class-string  $model
     */
    public function for(string $model): self
    {
        if (! is_subclass_of($model, Model::class)) {
            throw SchemaDefinitionException::notAnEloquentModel($model);
        }

        $this->model = $model;

        return $this;
    }

    /**
     * The name an agent uses to refer to this resource.
     */
    public function name(string $name): self
    {
        $this->resourceName = $name;

        return $this;
    }

    public function describe(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * A scope applied to every compiled query, before the agent's own filters.
     *
     * There is no plan shape that can remove or reorder these.
     *
     * @param  Closure(Builder<Model>, Authenticatable|null): mixed  $scope
     */
    public function alwaysScope(Closure $scope): self
    {
        $this->alwaysScopes[] = $scope;

        return $this;
    }

    public function defaultLimit(int $limit): self
    {
        if ($limit > $this->maxLimit) {
            throw SchemaDefinitionException::defaultLimitAboveMax($limit, $this->maxLimit);
        }

        $this->defaultLimit = $limit;

        return $this;
    }

    /**
     * Lowering the max below the current default clamps the default down with it.
     */
    public function maxLimit(int $limit): self
    {
        $this->maxLimit = $limit;
        $this->defaultLimit = min($this->defaultLimit, $limit);

        return $this;
    }

    public function maxGroups(int $groups): self
    {
        $this->maxGroups = $groups;

        return $this;
    }

    public function maxRelationDepth(int $depth): self
    {
        $this->maxRelationDepth = $depth;

        return $this;
    }

    public function limits(): Limits
    {
        return new Limits(
            default: $this->defaultLimit,
            max: $this->maxLimit,
            maxGroups: $this->maxGroups,
            maxRelationDepth: $this->maxRelationDepth,
        );
    }

    /** @return class-string<Model>|null */
    public function model(): ?string
    {
        return $this->model;
    }

    public function resourceName(): ?string
    {
        return $this->resourceName;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    /** @return list<Closure> */
    public function alwaysScopes(): array
    {
        return $this->alwaysScopes;
    }
}
