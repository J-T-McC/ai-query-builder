<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Plan;

use JTMcC\AiQueryBuilder\Plan\Enums\LogicalOperator;

/**
 * A nested boolean group.
 *
 * The whole tree is compiled inside a single wrapping closure so that an `or`
 * at the top of an agent's filters cannot escape a mandatory scope.
 */
final readonly class FilterGroup
{
    /**
     * @param  list<FilterCondition|FilterGroup>  $conditions
     */
    public function __construct(
        public LogicalOperator $operator,
        public array $conditions,
    ) {}

    /** @return array{operator: string, conditions: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'operator' => $this->operator->value,
            'conditions' => array_map(
                static fn (FilterCondition|FilterGroup $condition): array => $condition->toArray(),
                $this->conditions,
            ),
        ];
    }
}
