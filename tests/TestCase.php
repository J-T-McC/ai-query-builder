<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Tests;

use JTMcC\AiQueryBuilder\AiQueryBuilderServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            AiQueryBuilderServiceProvider::class,
        ];
    }
}
