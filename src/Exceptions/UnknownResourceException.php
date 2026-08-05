<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Exceptions;

use RuntimeException;

/**
 * Thrown when a resource is requested that was never registered.
 */
class UnknownResourceException extends RuntimeException
{
    /**
     * @param  list<string>  $registered
     */
    public static function named(string $name, array $registered): self
    {
        return new self(sprintf(
            'No query resource named [%s] is registered. Registered resources: %s.',
            $name,
            $registered === [] ? 'none' : implode(', ', $registered),
        ));
    }
}
