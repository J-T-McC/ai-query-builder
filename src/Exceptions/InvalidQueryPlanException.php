<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Exceptions;

use JTMcC\AiQueryBuilder\Validation\ValidationError;
use RuntimeException;

/**
 * Thrown when an untrusted query plan fails validation.
 *
 * Carries every error rather than the first, so an AI layer can correct a plan
 * in one pass. This is the seam a retry loop would wrap; the package itself
 * does not retry.
 */
class InvalidQueryPlanException extends RuntimeException
{
    /**
     * @param  list<ValidationError>  $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct(sprintf(
            'The query plan was rejected with %d error(s): %s',
            count($errors),
            implode(' ', array_map(
                static fn (ValidationError $error): string => "[{$error->path}] {$error->message}",
                $errors,
            )),
        ));
    }

    /** @return list<ValidationError> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_values(array_unique(array_map(
            static fn (ValidationError $error): string => $error->code->value,
            $this->errors,
        )));
    }

    /** @return list<array{path: string, code: string, message: string, did_you_mean?: string}> */
    public function toArray(): array
    {
        return array_map(
            static fn (ValidationError $error): array => $error->toArray(),
            $this->errors,
        );
    }
}
