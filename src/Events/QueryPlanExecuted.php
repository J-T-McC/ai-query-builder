<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use JTMcC\AiQueryBuilder\Plan\QueryPlan;

/**
 * A plan ran. This event is the audit record.
 *
 * It carries everything needed to reconstruct what happened — the plan, the SQL
 * and bindings it became, who ran it, and what came back. The package does not
 * persist it, so applications decide what to store and what to redact.
 */
final readonly class QueryPlanExecuted
{
    /**
     * @param  list<mixed>  $bindings
     */
    public function __construct(
        public QueryPlan $plan,
        public string $sql,
        public array $bindings,
        public int $rowCount,
        public float $durationMs,
        public bool $truncated,
        public ?Authenticatable $user = null,
        public ?string $prompt = null,
    ) {}
}
