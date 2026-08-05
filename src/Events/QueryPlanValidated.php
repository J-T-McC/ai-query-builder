<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use JTMcC\AiQueryBuilder\Plan\QueryPlan;

/**
 * A plan passed validation and is about to be compiled.
 *
 * This is the hook for a human-in-the-loop approval gate: a listener may
 * inspect the plan before any query runs.
 */
final readonly class QueryPlanValidated
{
    public function __construct(
        public QueryPlan $plan,
        public ?Authenticatable $user = null,
        public ?string $prompt = null,
    ) {}
}
