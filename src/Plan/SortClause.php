<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Plan;

use JTMcC\AiQueryBuilder\Plan\Enums\SortDirection;
use JTMcC\AiQueryBuilder\Schema\ColumnDefinition;

/**
 * An ordering, referencing either a sortable column or an aggregated select alias.
 */
final readonly class SortClause
{
    public function __construct(
        public string $reference,
        public SortDirection $direction,
        public ?ColumnDefinition $column = null,
    ) {}

    /**
     * True when this sorts a projected alias rather than an underlying column.
     */
    public function isAlias(): bool
    {
        return $this->column === null;
    }

    /** @return array{column: string, direction: string} */
    public function toArray(): array
    {
        return [
            'column' => $this->reference,
            'direction' => $this->direction->value,
        ];
    }
}
