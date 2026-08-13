<?php

use Illuminate\Support\Facades\Route;
use JTMcC\AiQueryBuilder\Mcp\QueryServer;
use JTMcC\AiQueryBuilder\Tests\Fixtures\InvoiceQuerySchema;
use JTMcC\AiQueryBuilder\Tests\Fixtures\ProductQuerySchema;
use Laravel\Mcp\Facades\Mcp;

Route::get('/', function () {
    return view('welcome');
});

// A live MCP server over the fixture schemas, for `composer serve` and
// `php artisan mcp:inspector mcp/query`. Unauthenticated on purpose: the
// workbench is a local sandbox, and a guest user exercises the same
// visibility rules the tests assert. Tests configure their own exposure.
if (! app()->runningUnitTests()) {
    config()->set('ai-query-builder.resources', [InvoiceQuerySchema::class, ProductQuerySchema::class]);
    config()->set('ai-query-builder.mcp.resources', ['invoices', 'products']);
}

Mcp::web('/mcp/query', QueryServer::class);
