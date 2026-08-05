<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Tests;

use JTMcC\AiQueryBuilder\AiQueryBuilderServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use WithWorkbench;

    protected function getPackageProviders($app): array
    {
        return [
            AiQueryBuilderServiceProvider::class,
        ];
    }
}
