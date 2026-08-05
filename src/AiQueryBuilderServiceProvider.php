<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use JTMcC\AiQueryBuilder\Schema\Contracts\DefinesQuerySchema;
use JTMcC\AiQueryBuilder\Schema\SchemaRegistry;

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

        $this->publishes([
            __DIR__.'/../config/ai-query-builder.php' => config_path('ai-query-builder.php'),
        ], ['ai-query-builder', 'ai-query-builder-config']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['ai-query-builder', 'ai-query-builder-migrations']);
    }
}
