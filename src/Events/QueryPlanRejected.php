<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use JTMcC\AiQueryBuilder\Exceptions\InvalidQueryPlanException;

/**
 * A plan failed validation and was not executed.
 *
 * Listen for this to measure how often an agent produces an invalid plan. That
 * rate is the evidence for whether retries are worth their token cost.
 */
final readonly class QueryPlanRejected
{
    /**
     * @param  array<string, mixed>  $input  The rejected plan, exactly as received.
     */
    public function __construct(
        public string $resource,
        public array $input,
        public InvalidQueryPlanException $exception,
        public ?Authenticatable $user = null,
        public ?string $prompt = null,
    ) {}

    /**
     * Deduplicated error codes, for grouping in metrics.
     *
     * @return list<string>
     */
    public function errorCodes(): array
    {
        return $this->exception->codes();
    }

    /**
     * @return list<array{path: string, code: string, message: string, did_you_mean?: string}>
     */
    public function errors(): array
    {
        return $this->exception->toArray();
    }
}
