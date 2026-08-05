<?php

declare(strict_types=1);

namespace AiQueryBuilder\AiQueryBuilder\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \AiQueryBuilder\AiQueryBuilder\AiQueryBuilder
 */
class AiQueryBuilder extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \AiQueryBuilder\AiQueryBuilder\AiQueryBuilder::class;
    }
}
