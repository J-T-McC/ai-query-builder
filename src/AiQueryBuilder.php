<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder;

use Illuminate\Contracts\Auth\Authenticatable;
use JTMcC\AiQueryBuilder\Ai\DescribeResourceTool;
use JTMcC\AiQueryBuilder\Ai\QueryResourcesTool;

class AiQueryBuilder
{
    /**
     * The pair of tools that expose several resources without paying for all of
     * them on every step.
     *
     * They have to agree on the resource list — one describes what can be
     * queried and the other decides what may be — so building them from one
     * array is the point. Registering them separately with lists that differ
     * fails quietly: the model is told about a resource it cannot query, or
     * queries one it was never told about.
     *
     *     public function tools(): iterable
     *     {
     *         return AiQueryBuilder::tools(['invoices', 'customers'], auth()->user());
     *     }
     *
     * @param  list<string>  $resources
     * @return list<DescribeResourceTool|QueryResourcesTool>
     */
    public function tools(array $resources, ?Authenticatable $user = null): array
    {
        return [
            new DescribeResourceTool($resources, $user),
            new QueryResourcesTool($resources, $user),
        ];
    }
}
