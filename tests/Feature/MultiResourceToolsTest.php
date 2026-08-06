<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use JTMcC\AiQueryBuilder\Ai\DescribeResourceTool;
use JTMcC\AiQueryBuilder\Ai\QueryDataTool;
use JTMcC\AiQueryBuilder\Ai\QueryResourcesTool;
use JTMcC\AiQueryBuilder\Exceptions\SchemaDefinitionException;
use JTMcC\AiQueryBuilder\Facades\AiQueryBuilder;
use JTMcC\AiQueryBuilder\Tests\Fixtures\InvoiceQuerySchema;
use JTMcC\AiQueryBuilder\Tests\Fixtures\ProductQuerySchema;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use Workbench\App\Models\Invoice;
use Workbench\App\Models\Product;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai-query-builder.resources', [InvoiceQuerySchema::class, ProductQuerySchema::class]);
});

/**
 * @param  list<string>  $resources
 * @return array<string, mixed>
 */
function multiSchema(array $resources = ['invoices', 'products']): array
{
    return array_map(
        fn ($type) => $type->toArray(),
        (new QueryResourcesTool($resources))->schema(new JsonSchemaTypeFactory),
    );
}

describe('describe_query_resource', function () {
    it('lists what can be queried without spending a dictionary on each', function () {
        $description = (new DescribeResourceTool(['invoices', 'products']))->description();

        expect($description)->toContain('- invoices — Customer invoices, one row per invoice.')
            ->toContain('- products')
            ->not->toContain('lines.product.type');
    });

    it('returns the dictionary for the resource the model asks about', function () {
        $result = (new DescribeResourceTool(['invoices', 'products']))
            ->handle(new Request(['resource' => 'invoices']));

        expect($result)->toContain('Resource: invoices')->toContain('lines.product.type');
    });

    it('refuses a resource outside its list even when it is registered', function () {
        $result = json_decode((new DescribeResourceTool(['invoices']))
            ->handle(new Request(['resource' => 'products'])), true);

        expect($result['error'])->toBe('unknown_resource')
            ->and($result['resources'])->toBe(['invoices']);
    });

    it('scopes the dictionary to the acting user', function () {
        $result = (new DescribeResourceTool(['invoices']))->handle(new Request(['resource' => 'invoices']));

        expect($result)->not->toContain('customer_notes');
    });
});

describe('query_data', function () {
    it('carries one schema for every resource', function () {
        $schema = multiSchema();

        expect($schema['resource']['enum'])->toBe(['invoices', 'products'])
            ->and($schema['select']['items']['properties']['column'])->not->toHaveKey('enum');
    });

    it('unions the shape so a clause only one resource can use stays expressible', function () {
        // Products declares nothing aggregatable or groupable; invoices does.
        expect(multiSchema(['products']))->not->toHaveKey('group_by')
            ->and(multiSchema())->toHaveKey('group_by')
            ->and(multiSchema())->toHaveKey('having');
    });

    it('publishes the most permissive row cap and lets the validator hold each resource to its own', function () {
        expect(multiSchema()['limit']['maximum'])->toBe(1000);
    });

    it('does not grow when another resource is added', function () {
        $shape = fn (array $resources) => array_diff_key(multiSchema($resources), ['resource' => null]);

        expect(json_encode($shape(['invoices'])))->toBe(json_encode($shape(['invoices', 'products'])));
    });

    it('runs a plan against the resource the model named', function () {
        Invoice::create(['tenant_id' => 1, 'issued_at' => '2026-02-01', 'total' => 42, 'status' => 'paid']);
        Product::create(['name' => 'Widget', 'type' => 'widget']);

        $result = json_decode((new QueryResourcesTool(['invoices', 'products']))->handle(new Request([
            'resource' => 'products',
            'select' => [['column' => 'product_name']],
        ])), true);

        expect($result['row_count'])->toBe(1)
            ->and($result['rows'][0]['product_name'])->toBe('Widget');
    });

    it('refuses a resource outside its list before the runner sees it', function () {
        Product::create(['name' => 'Widget', 'type' => 'widget']);

        $result = json_decode((new QueryResourcesTool(['invoices']))->handle(new Request([
            'resource' => 'products',
            'select' => [['column' => 'product_name']],
        ])), true);

        expect($result['error'])->toBe('unknown_resource');
    });

    it('returns correctable errors, since the schema no longer steers column names', function () {
        $result = json_decode((new QueryResourcesTool(['invoices']))->handle(new Request([
            'resource' => 'invoices',
            'select' => [['column' => 'totl']],
        ])), true);

        expect($result['error'])->toBe('invalid_query_plan')
            ->and($result['errors'][0]['did_you_mean'])->toBe('total');
    });

    it('refuses to be built with nothing to query', function () {
        new QueryResourcesTool([]);
    })->throws(SchemaDefinitionException::class);
});

describe('pairing', function () {
    it('builds both tools from one resource list', function () {
        $tools = AiQueryBuilder::tools(['invoices', 'products']);

        expect(array_map(fn ($tool) => ToolNameResolver::resolve($tool), $tools))
            ->toBe(['describe_query_resource', 'query_data']);
    });

    it('names every tool distinctly so they can share an agent', function () {
        $tools = [...AiQueryBuilder::tools(['invoices']), new QueryDataTool('products')];
        $names = array_map(fn ($tool) => ToolNameResolver::resolve($tool), $tools);

        expect($names)->toBe(array_unique($names));
    });
});
