<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Schema;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use JTMcC\AiQueryBuilder\Exceptions\SchemaDefinitionException;
use JTMcC\AiQueryBuilder\Schema\Concerns\DefinesStructure;
use JTMcC\AiQueryBuilder\Schema\Enums\ColumnType;
use JTMcC\AiQueryBuilder\Schema\Enums\Operator;

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

    private ?ColumnTypeResolver $types = null;

    private ?string $resourceName = null;

    private ?string $description = null;

    /** @var list<Closure> */
    private array $alwaysScopes = [];

    private int $defaultLimit = 100;

    private int $maxLimit = 1000;

    private int $maxRelationDepth = 2;

    private int $maxFilterDepth = 5;

    private int $maxFilterNodes = 50;

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

        // Anything already inferred was inferred from a different model.
        $this->types = null;

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

    public function maxRelationDepth(int $depth): self
    {
        $this->maxRelationDepth = $depth;

        return $this;
    }

    /**
     * How deeply filter groups may nest. A cost proxy, not a correctness bound.
     */
    public function maxFilterDepth(int $depth): self
    {
        $this->maxFilterDepth = $depth;

        return $this;
    }

    /**
     * How many conditions and groups a filter tree may contain in total.
     */
    public function maxFilterNodes(int $nodes): self
    {
        $this->maxFilterNodes = $nodes;

        return $this;
    }

    public function limits(): Limits
    {
        return new Limits(
            default: $this->defaultLimit,
            max: $this->maxLimit,
            maxRelationDepth: $this->maxRelationDepth,
            maxFilterDepth: $this->maxFilterDepth,
            maxFilterNodes: $this->maxFilterNodes,
        );
    }

    /**
     * The kind of value a column path holds, or null when nothing here knows.
     *
     * Declared on the column first, otherwise read from the model's casts. The
     * validator uses it to refuse a filter value that would compile into a
     * comparison meaning something other than what was asked.
     */
    public function typeOf(string $path, ?ColumnDefinition $column = null): ?ColumnType
    {
        $column ??= $this->findColumn($path);

        if ($column === null) {
            return null;
        }

        return ($this->types ??= new ColumnTypeResolver($this->model))->resolve($path, $column);
    }

    /**
     * Whether a column accepts a named date range as well as a literal one.
     *
     * Derived rather than declared: `within` compiles to the same bounded
     * comparison `between` already permits, on bounds the package works out
     * instead of the agent. It grants no reach a schema author did not already
     * grant, so requiring a second declaration would be friction for nothing.
     */
    public function permitsWindow(string $path, ?ColumnDefinition $column = null): bool
    {
        $column ??= $this->findColumn($path);

        if ($column === null || ! $column->allowsOperator(Operator::Between)) {
            return false;
        }

        $type = $this->typeOf($path, $column);

        return $type === ColumnType::Date || $type === ColumnType::Datetime;
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
