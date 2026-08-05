<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \JTMcC\AiQueryBuilder\AiQueryBuilder
 */
class AiQueryBuilder extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \JTMcC\AiQueryBuilder\AiQueryBuilder::class;
    }
}
