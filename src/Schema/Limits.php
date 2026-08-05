<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Schema;

/**
 * Ceilings a query plan is clamped to at compile time.
 */
final readonly class Limits
{
    public function __construct(
        public int $default = 100,
        public int $max = 1000,
        public int $maxGroups = 500,
        public int $maxRelationDepth = 2,
    ) {}
}
