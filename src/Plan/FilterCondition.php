<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Plan;

use JTMcC\AiQueryBuilder\Schema\ColumnDefinition;
use JTMcC\AiQueryBuilder\Schema\Enums\Operator;

/**
 * A single comparison. The value is always data and is always bound as a
 * parameter at compile time; it is never interpolated into SQL.
 */
final readonly class FilterCondition
{
    public function __construct(
        public string $path,
        public ColumnDefinition $column,
        public Operator $operator,
        public mixed $value = null,
    ) {}

    /** @return array{column: string, operator: string, value?: mixed} */
    public function toArray(): array
    {
        $condition = [
            'column' => $this->path,
            'operator' => $this->operator->value,
        ];

        if ($this->value !== null) {
            $condition['value'] = $this->value;
        }

        return $condition;
    }
}
