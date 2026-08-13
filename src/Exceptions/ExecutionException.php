<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Exceptions;

use RuntimeException;

class ExecutionException extends RuntimeException
{
    public static function invalidMaxRows(int $rows): self
    {
        return new self("The maximum row count must be greater than zero; [{$rows}] given.");
    }

    /**
     * Refused rather than ignored: silently dropping a timeout would leave the
     * caller believing a guardrail is in place when it is not.
     */
    public static function timeoutUnsupported(string $driver): self
    {
        return new self(
            "A statement timeout cannot be enforced on the [{$driver}] driver. ".
            'Supported drivers are pgsql, mysql and mariadb. Set the timeout to null to run without one.',
        );
    }

    public static function missingResource(): self
    {
        return new self('A query plan must name the resource it targets.');
    }
}
