<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use JTMcC\AiQueryBuilder\Compilation\PlanCompiler;
use JTMcC\AiQueryBuilder\Console\DescribeSchemaCommand;
use JTMcC\AiQueryBuilder\Console\MakeSchemaCommand;
use JTMcC\AiQueryBuilder\Execution\QueryRunner;
use JTMcC\AiQueryBuilder\Schema\Contracts\DefinesQuerySchema;
use JTMcC\AiQueryBuilder\Schema\SchemaRegistry;
use JTMcC\AiQueryBuilder\Validation\PlanValidator;

class AiQueryBuilderServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-query-builder.php', 'ai-query-builder');

        $this->app->singleton(AiQueryBuilder::class);

        $this->app->singleton(SchemaRegistry::class, function (Container $app): SchemaRegistry {
            /** @var Repository $config */
            $config = $app->make(Repository::class);

            /** @var list<class-string<DefinesQuerySchema>> $resources */
            $resources = $config->get('ai-query-builder.resources', []);

            return new SchemaRegistry($app, $resources);
        });

        // Bound rather than shared: the fluent setters clone, but a fresh
        // instance per resolve keeps configured defaults unambiguous.
        $this->app->bind(QueryRunner::class, function (Container $app): QueryRunner {
            /** @var Repository $config */
            $config = $app->make(Repository::class);

            /** @var string|null $connection */
            $connection = $config->get('ai-query-builder.execution.connection');

            /** @var int|null $timeout */
            $timeout = $config->get('ai-query-builder.execution.timeout');

            /** @var int $maxRows */
            $maxRows = $config->get('ai-query-builder.execution.max_rows', 1000);

            return new QueryRunner(
                $app->make(SchemaRegistry::class),
                $app->make(PlanValidator::class),
                $app->make(PlanCompiler::class),
                $app->make(Dispatcher::class),
                connection: $connection,
                timeout: $timeout,
                maxRows: $maxRows,
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/ai-query-builder.php');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            DescribeSchemaCommand::class,
            MakeSchemaCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/ai-query-builder.php' => config_path('ai-query-builder.php'),
        ], ['ai-query-builder', 'ai-query-builder-config']);
    }
}
