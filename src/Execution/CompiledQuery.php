<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Execution;

use JTMcC\AiQueryBuilder\Plan\QueryPlan;

/**
 * What a plan compiles to, without running it.
 *
 * Returned by QueryRunner::explain() so a plan can be shown to a human for
 * approval, or logged, before any query touches the database.
 */
final readonly class CompiledQuery
{
    /**
     * @param  list<mixed>  $bindings
     */
    public function __construct(
        public QueryPlan $plan,
        public string $sql,
        public array $bindings,
    ) {}

    /**
     * @return array{plan: array<string, mixed>, sql: string, bindings: list<mixed>}
     */
    public function toArray(): array
    {
        return [
            'plan' => $this->plan->toArray(),
            'sql' => $this->sql,
            'bindings' => $this->bindings,
        ];
    }
}
