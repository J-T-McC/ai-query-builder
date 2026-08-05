<?php

declare(strict_types=1);

namespace AiQueryBuilder\AiQueryBuilder\Schema;

use AiQueryBuilder\AiQueryBuilder\Exceptions\SchemaDefinitionException;
use AiQueryBuilder\AiQueryBuilder\Exceptions\UnknownResourceException;
use AiQueryBuilder\AiQueryBuilder\Schema\Contracts\DefinesQuerySchema;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves and indexes the resources exposed to AI agents.
 *
 * Definitions are resolved lazily on first access so nothing touches the host
 * application during service provider registration.
 */
final class SchemaRegistry
{
    /** @var list<class-string<DefinesQuerySchema>> */
    private array $definitions;

    /** @var array<string, ResourceSchema>|null */
    private ?array $resolved = null;

    /**
     * @param  list<class-string<DefinesQuerySchema>>  $definitions
     */
    public function __construct(
        private readonly Container $container,
        array $definitions = [],
    ) {
        $this->definitions = $definitions;
    }

    /**
     * @param  class-string<DefinesQuerySchema>  $definition
     */
    public function register(string $definition): self
    {
        $this->definitions[] = $definition;
        $this->resolved = null;

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    public function get(string $name): ResourceSchema
    {
        return $this->all()[$name]
            ?? throw UnknownResourceException::named($name, $this->names());
    }

    /** @return array<string, ResourceSchema> */
    public function all(): array
    {
        return $this->resolved ??= $this->resolve();
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->all());
    }

    /** @return array<string, ResourceSchema> */
    private function resolve(): array
    {
        $schemas = [];

        foreach ($this->definitions as $definition) {
            /** @var DefinesQuerySchema $instance */
            $instance = $this->container->make($definition);

            $schema = $instance->define(ResourceSchema::make());
            $name = $schema->resourceName();

            if ($name === null) {
                throw SchemaDefinitionException::missingResourceName($definition);
            }

            if (isset($schemas[$name])) {
                throw SchemaDefinitionException::duplicateResource($name);
            }

            $schemas[$name] = $schema;
        }

        return $schemas;
    }
}
