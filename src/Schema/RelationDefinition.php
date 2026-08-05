<?php

declare(strict_types=1);

namespace AiQueryBuilder\AiQueryBuilder\Schema;

use AiQueryBuilder\AiQueryBuilder\Schema\Concerns\DefinesStructure;

/**
 * A traversable Eloquent relation and the columns it exposes.
 *
 * The relation name is the method on the parent model. The compiler derives the
 * join from that relation, so an agent can never express a join condition itself.
 */
final class RelationDefinition
{
    use DefinesStructure;

    private ?string $description = null;

    public function __construct(private readonly string $name) {}

    public function describe(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }
}
