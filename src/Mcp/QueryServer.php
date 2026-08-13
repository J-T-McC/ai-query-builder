<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Mcp;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use JTMcC\AiQueryBuilder\Mcp\Tools\DescribeResourceTool;
use JTMcC\AiQueryBuilder\Mcp\Tools\QueryResourcesTool;
use Laravel\Mcp\Server;

/**
 * A ready-made MCP server over the exposed query resources.
 *
 * Register it directly and drive exposure from the `mcp.resources` config
 * key, or subclass it once per audience — the MCP analog of composing one
 * Laravel AI agent per audience:
 *
 *     // routes/ai.php
 *     Mcp::web('/mcp/query', QueryServer::class)->middleware('auth:sanctum');
 *
 *     // One endpoint per audience, each with its own exposure.
 *     class AdminQueryServer extends QueryServer
 *     {
 *         protected array|string $exposes = ['invoices', 'customers'];
 *     }
 *
 * `$exposes` also accepts a ResolvesExposedResources class-string, evaluated
 * per request with the authenticated user, so one endpoint can show different
 * users different catalogues.
 *
 * The tools are built here as instances so the exposure travels with them;
 * boot() runs per request on the web transport, before any tool is listed or
 * called.
 */
class QueryServer extends Server
{
    protected string $name = 'AI Query Builder';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
    Query registered application resources safely. Call describe_query_resource
    to learn a resource's columns before querying it with query_data.
    MARKDOWN;

    /**
     * Resource names to expose, or a ResolvesExposedResources class-string.
     * Empty means the `ai-query-builder.mcp.resources` config key decides.
     *
     * @var array<int, string>|string
     */
    protected array|string $exposes = [];

    protected function boot(): void
    {
        $exposes = $this->exposes();

        $this->tools = [
            new DescribeResourceTool($exposes),
            new QueryResourcesTool($exposes),
        ];
    }

    /**
     * @return array<int, string>|string
     */
    protected function exposes(): array|string
    {
        if ($this->exposes !== []) {
            return $this->exposes;
        }

        /** @var array<int, string>|string */
        return Container::getInstance()->make(Repository::class)->get('ai-query-builder.mcp.resources', []);
    }
}
