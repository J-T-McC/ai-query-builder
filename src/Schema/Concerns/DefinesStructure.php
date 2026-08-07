<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Schema\Concerns;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use JTMcC\AiQueryBuilder\Exceptions\SchemaDefinitionException;
use JTMcC\AiQueryBuilder\Schema\ColumnDefinition;
use JTMcC\AiQueryBuilder\Schema\PivotDefinition;
use JTMcC\AiQueryBuilder\Schema\RelationDefinition;
use JTMcC\AiQueryBuilder\Schema\ResourceSchema;

/**
 * Column and relation declaration shared by resources and their relations.
 *
 * Both nodes hold the same structure, so path traversal can walk from a resource
 * into nested relations without special-casing the root.
 */
trait DefinesStructure
{
    use DeclaresColumns;

    /** @var array<string, RelationDefinition> */
    private array $relations = [];

    /**
     * Declare a traversable Eloquent relation.
     *
     * @param  (Closure(RelationDefinition): mixed)|null  $configure
     */
    public function relation(string $name, ?Closure $configure = null): static
    {
        if ($name === PivotDefinition::SEGMENT) {
            throw SchemaDefinitionException::reservedRelationName($name);
        }

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
     *
     * The pivot segment does not count. It reaches the intermediate table of a
     * relation already counted, so charging for it would make a many-to-many
     * cost double what a has-many costs to reach the same distance.
     */
    public function depthOf(string $path): int
    {
        $segments = explode('.', $path);
        array_pop($segments);

        return count(array_filter($segments, static fn (string $s): bool => $s !== PivotDefinition::SEGMENT));
    }

    /**
     * Every column path visible to this user, including those behind relations.
     *
     * Drives both the contract handed to an agent and the "did you mean"
     * suggestions on validation errors, so a hidden column can never leak
     * through a suggestion.
     *
     * @return list<string>
     */
    public function columnPaths(?Authenticatable $user, string $prefix = ''): array
    {
        return array_keys($this->visibleColumnMap($user, $prefix));
    }

    /**
     * Every visible column keyed by its full path, including those behind relations.
     *
     * @return array<string, ColumnDefinition>
     */
    public function visibleColumnMap(?Authenticatable $user, string $prefix = ''): array
    {
        $columns = [];

        foreach ($this->visibleColumns($user) as $name => $column) {
            $columns[$prefix.$name] = $column;
        }

        foreach ($this->relations as $name => $relation) {
            $columns = [...$columns, ...$relation->visibleColumnMap($user, $prefix.$name.'.')];

            $pivot = $relation->pivotDefinition();

            if ($pivot === null) {
                continue;
            }

            foreach ($pivot->visibleColumns($user) as $pivotName => $pivotColumn) {
                $columns[$prefix.$name.'.'.PivotDefinition::SEGMENT.'.'.$pivotName] = $pivotColumn;
            }
        }

        return $columns;
    }

    /**
     * Walk the declared relation segments, stopping at the first undeclared one.
     *
     * @param  list<string>  $segments
     */
    private function traverse(array $segments): ResourceSchema|RelationDefinition|PivotDefinition|null
    {
        $node = $this;

        foreach ($segments as $segment) {
            // A pivot is the end of the line: it holds columns and nothing else,
            // so any segment after it names something that cannot exist.
            if ($node instanceof PivotDefinition) {
                return null;
            }

            if ($segment === PivotDefinition::SEGMENT) {
                $node = $node instanceof RelationDefinition ? $node->pivotDefinition() : null;

                if ($node === null) {
                    return null;
                }

                continue;
            }

            $node = $node->relations()[$segment] ?? null;

            if ($node === null) {
                return null;
            }
        }

        return $node;
    }
}
