<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static list<\JTMcC\AiQueryBuilder\Ai\DescribeResourceTool|\JTMcC\AiQueryBuilder\Ai\QueryResourcesTool> tools(list<string> $resources, \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 *
 * @see \JTMcC\AiQueryBuilder\AiQueryBuilder
 */
class AiQueryBuilder extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \JTMcC\AiQueryBuilder\AiQueryBuilder::class;
    }
}
