<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Tests\Fixtures;

use JTMcC\AiQueryBuilder\Mcp\QueryServer;

/**
 * A per-audience server: exposure pinned on the class, ignoring config.
 */
final class ProductQueryServer extends QueryServer
{
    /**
     * @var array<int, string>|string
     */
    protected array|string $exposes = ['products'];
}
