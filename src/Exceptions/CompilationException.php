<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Exceptions;

use RuntimeException;

/**
 * Thrown when a validated plan cannot be compiled into a safe query.
 *
 * A plan reaching this point passed validation, so these are limits of the
 * compiler or of the schema's declared shape rather than untrusted input.
 */
class CompilationException extends RuntimeException
{
    public static function missingModel(string $resource): self
    {
        return new self("The resource [{$resource}] has no Eloquent model bound to it.");
    }

    public static function unsupportedRelation(string $path, string $type): self
    {
        return new self(
            "The relation [{$path}] is a {$type}, which this package cannot join. ".
            'Supported relations are HasOne, HasMany and BelongsTo.',
        );
    }

    public static function unsupportedDateBucket(string $driver, string $bucket): self
    {
        return new self("Date bucketing by [{$bucket}] is not implemented for the [{$driver}] driver.");
    }

    /**
     * A to-many join multiplies parent rows, so aggregating a column above that
     * join counts the same row once per child and silently inflates the result.
     */
    public static function fanOutAggregate(string $column, string $relation): self
    {
        return new self(
            "Aggregating [{$column}] while joining the to-many relation [{$relation}] would count ".
            'each row once per related row and inflate the result. Aggregate a column on ['.
            $relation.'] instead, or drop the relation from this plan.',
        );
    }
}
