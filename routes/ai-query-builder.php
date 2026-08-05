<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JTMcC\AiQueryBuilder\Http\Controllers\QueryController;

if (config('ai-query-builder.routes.enabled') !== true) {
    return;
}

/** @var list<string> $middleware */
$middleware = config('ai-query-builder.routes.middleware', ['api']);

/** @var string $prefix */
$prefix = config('ai-query-builder.routes.prefix', 'ai-query');

Route::middleware($middleware)
    ->prefix($prefix)
    ->post('{resource}/query', QueryController::class)
    ->name('ai-query-builder.query');
