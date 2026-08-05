<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Plan;

use JTMcC\AiQueryBuilder\Schema\ColumnDefinition;
use JTMcC\AiQueryBuilder\Schema\Enums\Aggregate;

/**
 * One projected value.
 *
 * Holds the resolved ColumnDefinition rather than the raw path, so the compiler
 * builds identifiers from schema objects and never from plan strings.
 */
final readonly class SelectClause
{
    public function __construct(
        public string $path,
        public ColumnDefinition $column,
        public ?Aggregate $function,
        public string $alias,
    ) {}

    public function isAggregated(): bool
    {
        return $this->function !== null;
    }

    /** @return array{column: string, function?: string, as: string} */
    public function toArray(): array
    {
        $clause = ['column' => $this->path];

        if ($this->function !== null) {
            $clause['function'] = $this->function->value;
        }

        $clause['as'] = $this->alias;

        return $clause;
    }
}
