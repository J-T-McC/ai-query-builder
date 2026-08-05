<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Validation;

/**
 * A single reason a query plan was rejected, addressed to the plan that failed.
 *
 * The path mirrors the input structure (`select.1.column`) so an AI layer can
 * point at exactly what to change without re-deriving the plan.
 */
final readonly class ValidationError
{
    public function __construct(
        public string $path,
        public ValidationCode $code,
        public string $message,
        public ?string $didYouMean = null,
    ) {}

    /**
     * @return array{path: string, code: string, message: string, did_you_mean?: string}
     */
    public function toArray(): array
    {
        $error = [
            'path' => $this->path,
            'code' => $this->code->value,
            'message' => $this->message,
        ];

        if ($this->didYouMean !== null) {
            $error['did_you_mean'] = $this->didYouMean;
        }

        return $error;
    }
}
