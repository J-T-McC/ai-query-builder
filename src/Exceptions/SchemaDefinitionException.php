<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a developer declares an invalid schema.
 *
 * This is always a programming error surfaced at definition time, never a
 * response to untrusted input from an AI agent.
 */
class SchemaDefinitionException extends InvalidArgumentException
{
    /**
     * @param  list<string>  $allowed
     */
    public static function unknownValue(string $kind, string $given, array $allowed): self
    {
        return new self(sprintf(
            'Unknown %s [%s]. Allowed values are: %s.',
            $kind,
            $given,
            implode(', ', $allowed),
        ));
    }

    public static function duplicateColumn(string $name): self
    {
        return new self("A column named [{$name}] is already declared on this schema.");
    }

    public static function duplicateRelation(string $name): self
    {
        return new self("A relation named [{$name}] is already declared on this schema.");
    }

    public static function notAnEloquentModel(string $class): self
    {
        return new self("The class [{$class}] is not an Eloquent model.");
    }

    public static function duplicateResource(string $name): self
    {
        return new self("A resource named [{$name}] is already registered.");
    }

    public static function emptyResourceList(string $tool): self
    {
        return new self("The tool [{$tool}] was given no resources. Name at least one it may query.");
    }

    public static function invalidResourceName(string $name): self
    {
        return new self(
            "The resource name [{$name}] is not usable as a tool name. Use only letters, numbers, underscores and hyphens.",
        );
    }

    public static function missingResourceName(string $definition): self
    {
        return new self("The schema definition [{$definition}] did not declare a resource name.");
    }

    public static function defaultLimitAboveMax(int $default, int $max): self
    {
        return new self("The default limit [{$default}] cannot exceed the max limit [{$max}].");
    }
}
