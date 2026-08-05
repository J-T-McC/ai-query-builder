<?php

declare(strict_types=1);

namespace AiQueryBuilder\AiQueryBuilder\Schema\Concerns;

use AiQueryBuilder\AiQueryBuilder\Exceptions\SchemaDefinitionException;
use AiQueryBuilder\AiQueryBuilder\Schema\ColumnDefinition;
use AiQueryBuilder\AiQueryBuilder\Schema\RelationDefinition;
use AiQueryBuilder\AiQueryBuilder\Schema\ResourceSchema;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Column and relation declaration shared by resources and their relations.
 *
 * Both nodes hold the same structure, so path traversal can walk from a resource
 * into nested relations without special-casing the root.
 */
trait DefinesStructure
{
    /** @var array<string, ColumnDefinition> */
    private array $columns = [];

    /** @var array<string, RelationDefinition> */
    private array $relations = [];

    /**
     * Declare a column. Without a callback the column is selectable and nothing else.
     *
     * @param  (Closure(ColumnDefinition): mixed)|null  $configure
     */
    public function column(string $name, ?Closure $configure = null): static
    {
        $column = new ColumnDefinition($name);

        if ($configure !== null) {
            $configure($column);
        }

        if (isset($this->columns[$column->exposedName()])) {
            throw SchemaDefinitionException::duplicateColumn($column->exposedName());
        }

        $this->columns[$column->exposedName()] = $column;

        return $this;
    }

    /**
     * Declare a traversable Eloquent relation.
     *
     * @param  (Closure(RelationDefinition): mixed)|null  $configure
     */
    public function relation(string $name, ?Closure $configure = null): static
    {
        if (isset($this->relations[$name])) {
            throw SchemaDefinitionException::duplicateRelation($name);
        }

        $relation = new RelationDefinition($name);

        if ($configure !== null) {
            $configure($relation);
        }

        $this->relations[$name] = $relation;

        return $this;
    }

    /** @return array<string, ColumnDefinition> */
    public function columns(): array
    {
        return $this->columns;
    }

    /** @return array<string, RelationDefinition> */
    public function relations(): array
    {
        return $this->relations;
    }

    /**
     * Resolve a dot-delimited path such as `lines.product.type` to its column.
     *
     * Returns null for any path that was not declared. Enforcing the relation
     * depth limit is the validator's job, not this method's.
     */
    public function findColumn(string $path): ?ColumnDefinition
    {
        $segments = explode('.', $path);
        $columnName = array_pop($segments);

        return $this->traverse($segments)?->columns()[$columnName] ?? null;
    }

    /**
     * Resolve a dot-delimited relation path such as `lines.product`.
     */
    public function findRelation(string $path): ?RelationDefinition
    {
        $node = $this->traverse(explode('.', $path));

        return $node instanceof RelationDefinition ? $node : null;
    }

    /**
     * How many relations a path traverses. `total` is 0, `lines.product.type` is 2.
     */
    public function depthOf(string $path): int
    {
        return substr_count($path, '.');
    }

    /**
     * Columns this user may see. Hidden columns never reach the agent's contract.
     *
     * @return array<string, ColumnDefinition>
     */
    public function visibleColumns(?Authenticatable $user): array
    {
        return array_filter(
            $this->columns,
            static fn (ColumnDefinition $column): bool => $column->isVisibleTo($user),
        );
    }

    /**
     * Walk the declared relation segments, stopping at the first undeclared one.
     *
     * @param  list<string>  $segments
     */
    private function traverse(array $segments): ResourceSchema|RelationDefinition|null
    {
        $node = $this;

        foreach ($segments as $segment) {
            $node = $node->relations()[$segment] ?? null;

            if ($node === null) {
                return null;
            }
        }

        return $node;
    }
}
