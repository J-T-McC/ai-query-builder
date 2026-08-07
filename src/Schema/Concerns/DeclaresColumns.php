<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Schema\Concerns;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use JTMcC\AiQueryBuilder\Exceptions\SchemaDefinitionException;
use JTMcC\AiQueryBuilder\Schema\ColumnDefinition;

/**
 * Column declaration, shared by every node that exposes columns.
 *
 * Split from DefinesStructure because a pivot holds columns but cannot hold
 * relations: nothing is traversable through an intermediate table.
 */
trait DeclaresColumns
{
    /** @var array<string, ColumnDefinition> */
    private array $columns = [];

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

    /** @return array<string, ColumnDefinition> */
    public function columns(): array
    {
        return $this->columns;
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
}
