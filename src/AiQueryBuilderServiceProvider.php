<?php

declare(strict_types=1);

namespace AiQueryBuilder\AiQueryBuilder;

use Illuminate\Support\ServiceProvider;

class AiQueryBuilderServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-query-builder.php', 'ai-query-builder');

        $this->app->singleton(AiQueryBuilder::class);
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
