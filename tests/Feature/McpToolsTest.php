<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use JTMcC\AiQueryBuilder\Ai\QueryResourcesTool as AiQueryResourcesTool;
use JTMcC\AiQueryBuilder\Exceptions\SchemaDefinitionException;
use JTMcC\AiQueryBuilder\Mcp\QueryServer;
use JTMcC\AiQueryBuilder\Mcp\Tools\DescribeResourceTool;
use JTMcC\AiQueryBuilder\Mcp\Tools\QueryResourcesTool;
use JTMcC\AiQueryBuilder\Tests\Fixtures\AuthenticatedOnlyResources;
use JTMcC\AiQueryBuilder\Tests\Fixtures\InvoiceQuerySchema;
use JTMcC\AiQueryBuilder\Tests\Fixtures\ProductQuerySchema;
use JTMcC\AiQueryBuilder\Tests\Fixtures\ProductQueryServer;
use Workbench\App\Models\Invoice;
use Workbench\App\Models\Product;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai-query-builder.resources', [InvoiceQuerySchema::class, ProductQuerySchema::class]);
    config()->set('ai-query-builder.mcp.resources', ['invoices', 'products']);
});

describe('describe_query_resource', function () {
    it('returns the dictionary through the server', function () {
        QueryServer::tool(DescribeResourceTool::class, ['resource' => 'invoices'])
            ->assertOk()
            ->assertSee('Resource: invoices')
            ->assertSee('lines.product.type');
    });

    it('scopes the dictionary to the authenticated user', function () {
        QueryServer::tool(DescribeResourceTool::class, ['resource' => 'invoices'])
            ->assertOk()
            ->assertDontSee('customer_notes');

        QueryServer::actingAs(new User)
            ->tool(DescribeResourceTool::class, ['resource' => 'invoices'])
            ->assertOk()
            ->assertSee('customer_notes');
    });

    it('refuses a resource outside the exposure even when it is registered', function () {
        config()->set('ai-query-builder.mcp.resources', ['invoices']);

        QueryServer::tool(DescribeResourceTool::class, ['resource' => 'products'])
            ->assertHasErrors();
    });

    it('lists only exposed resources in its catalogue', function () {
        $description = (new DescribeResourceTool(['invoices']))->description();

        expect($description)->toContain('- invoices — Customer invoices, one row per invoice.')
            ->not->toContain('products');
    });
});

describe('query_data', function () {
    it('runs a plan through the server and returns rows', function () {
        Product::create(['name' => 'Widget', 'type' => 'widget']);

        QueryServer::tool(QueryResourcesTool::class, [
            'resource' => 'products',
            'select' => [['column' => 'product_name']],
        ])
            ->assertOk()
            ->assertSee('Widget');
    });

    it('runs the plan as the authenticated user', function () {
        Invoice::create(['tenant_id' => 1, 'issued_at' => '2026-02-01', 'total' => 42, 'status' => 'paid']);

        QueryServer::actingAs(new User)
            ->tool(QueryResourcesTool::class, [
                'resource' => 'invoices',
                'select' => [['column' => 'customer_notes']],
            ])
            ->assertOk()
            ->assertHasNoErrors();
    });

    it('returns correctable errors as normal content, not MCP errors', function () {
        QueryServer::tool(QueryResourcesTool::class, [
            'resource' => 'invoices',
            'select' => [['column' => 'totl']],
        ])
            ->assertHasNoErrors()
            ->assertSee('invalid_query_plan')
            ->assertSee('total');
    });

    it('refuses a resource outside the exposure before the runner sees it', function () {
        config()->set('ai-query-builder.mcp.resources', ['invoices']);

        QueryServer::tool(QueryResourcesTool::class, [
            'resource' => 'products',
            'select' => [['column' => 'product_name']],
        ])
            ->assertHasErrors();
    });

    it('builds the same plan schema as the Laravel AI door', function () {
        $build = fn (object $tool): string => json_encode(array_map(
            fn ($type) => $type->toArray(),
            $tool->schema(new JsonSchemaTypeFactory),
        ), JSON_THROW_ON_ERROR);

        expect($build(new QueryResourcesTool(['invoices', 'products'])))
            ->toBe($build(new AiQueryResourcesTool(['invoices', 'products'])));
    });
});

describe('exposure', function () {
    it('narrows the catalogue per user through a resolver', function () {
        config()->set('ai-query-builder.mcp.resources', AuthenticatedOnlyResources::class);

        QueryServer::tool(DescribeResourceTool::class, ['resource' => 'invoices'])
            ->assertHasErrors();

        QueryServer::actingAs(new User)
            ->tool(DescribeResourceTool::class, ['resource' => 'invoices'])
            ->assertOk();
    });

    it('hides the tools entirely when nothing is exposed', function () {
        config()->set('ai-query-builder.mcp.resources', []);

        expect((new QueryResourcesTool)->shouldRegister())->toBeFalse()
            ->and((new DescribeResourceTool)->shouldRegister())->toBeFalse()
            ->and((new DescribeResourceTool(['invoices']))->shouldRegister())->toBeTrue();
    });

    it('lets a server subclass pin its own exposure over config', function () {
        Product::create(['name' => 'Widget', 'type' => 'widget']);

        ProductQueryServer::tool(QueryResourcesTool::class, [
            'resource' => 'invoices',
            'select' => [['column' => 'total']],
        ])
            ->assertHasErrors();

        ProductQueryServer::tool(QueryResourcesTool::class, [
            'resource' => 'products',
            'select' => [['column' => 'product_name']],
        ])
            ->assertOk()
            ->assertSee('Widget');
    });

    it('rejects a resolver that does not implement the contract', function () {
        config()->set('ai-query-builder.mcp.resources', stdClass::class);

        (new DescribeResourceTool)->description();
    })->throws(SchemaDefinitionException::class, 'ResolvesExposedResources');
});
