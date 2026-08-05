<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Plan;

use JTMcC\AiQueryBuilder\Schema\Enums\Operator;

/**
 * A filter applied after aggregation.
 *
 * Always references an aggregated select alias, never a raw column, so it can
 * only ever constrain a value the plan already projected.
 */
final readonly class HavingClause
{
    public function __construct(
        public string $alias,
        public Operator $operator,
        public mixed $value = null,
    ) {}

    /** @return array{column: string, operator: string, value?: mixed} */
    public function toArray(): array
    {
        $clause = [
            'column' => $this->alias,
            'operator' => $this->operator->value,
        ];

        if ($this->value !== null) {
            $clause['value'] = $this->value;
        }

        return $clause;
    }
}
