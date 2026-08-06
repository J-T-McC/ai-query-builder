<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use JTMcC\AiQueryBuilder\Ai\QueryDataTool;
use JTMcC\AiQueryBuilder\Tests\Fixtures\InvoiceQuerySchema;
use JTMcC\AiQueryBuilder\Tests\Fixtures\ProductQuerySchema;
use Laravel\Ai\Tools\Request;
use Workbench\App\Models\Invoice;
use Workbench\App\Models\Product;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai-query-builder.resources', [InvoiceQuerySchema::class]);
});

function tool(?object $user = null): QueryDataTool
{
    return new QueryDataTool('invoices', $user);
}

/**
 * @return array<string, mixed>
 */
function toolSchema(?object $user = null): array
{
    return array_map(
        fn ($type) => $type->toArray(),
        tool($user)->schema(new JsonSchemaTypeFactory),
    );
}

describe('description', function () {
    it('describes the resource as the data dictionary', function () {
        expect(tool()->description())
            ->toContain('Resource: invoices')
            ->toContain('lines.product.type');
    });

    it('omits columns the acting user cannot see', function () {
        expect(tool()->description())->not->toContain('customer_notes')
            ->and(tool(new User)->description())->toContain('customer_notes');
    });
});

describe('schema', function () {
    it('pins the resource', function () {
        expect(toolSchema()['resource']['enum'])->toBe(['invoices']);
    });

    it('restricts select columns to the visible declared set', function () {
        $columns = toolSchema()['select']['items']['properties']['column']['enum'];

        expect($columns)->toContain('total')
            ->toContain('lines.product.type')
            ->not->toContain('customer_notes');
    });

    it('nests filter groups to a bounded depth', function () {
        $filters = toolSchema()['filters'];

        // Depth 3: group -> group -> group, innermost accepting conditions only.
        $level2 = $filters['properties']['conditions']['items']['anyOf'][1];
        $level3 = $level2['properties']['conditions']['items']['anyOf'][1];

        expect($level3['properties']['conditions']['items'])
            ->toHaveKey('properties')
            ->and($level3['properties']['conditions']['items'])->not->toHaveKey('anyOf');
    });

    it('caps the limit at the schema maximum', function () {
        expect(toolSchema()['limit']['maximum'])->toBe(1000);
    });

    it('forbids unknown keys in a select entry', function () {
        expect(toolSchema()['select']['items']['additionalProperties'])->toBeFalse();
    });
});

describe('handle', function () {
    it('runs a plan and returns rows as json', function () {
        Invoice::create([
            'tenant_id' => 1, 'issued_at' => '2026-02-01', 'total' => 42, 'status' => 'paid',
        ]);

        $result = json_decode(tool()->handle(new Request([
            'resource' => 'invoices',
            'select' => [['column' => 'invoice_id'], ['column' => 'total']],
        ])), true);

        expect($result['row_count'])->toBe(1)
            ->and($result['columns']['total'])->toBe(['unit' => 'currency:USD']);
    });

    it('defaults the resource so the model need not repeat it', function () {
        Invoice::create([
            'tenant_id' => 1, 'issued_at' => '2026-02-01', 'total' => 42, 'status' => 'paid',
        ]);

        $result = json_decode(tool()->handle(new Request([
            'select' => [['column' => 'invoice_id']],
        ])), true);

        expect($result['row_count'])->toBe(1);
    });

    it('returns correctable errors to the model instead of throwing', function () {
        $result = json_decode(tool()->handle(new Request([
            'select' => [['column' => 'totl']],
        ])), true);

        expect($result['error'])->toBe('invalid_query_plan')
            ->and($result['errors'][0]['path'])->toBe('select.0.column')
            ->and($result['errors'][0]['did_you_mean'])->toBe('total');
    });

    it('runs against its own resource when the model names another', function () {
        config()->set('ai-query-builder.resources', [InvoiceQuerySchema::class, ProductQuerySchema::class]);

        Invoice::create([
            'tenant_id' => 1, 'issued_at' => '2026-02-01', 'total' => 42, 'status' => 'paid',
        ]);
        Product::create(['name' => 'Widget', 'type' => 'widget']);

        $result = json_decode(tool()->handle(new Request([
            'resource' => 'products',
            'select' => [['column' => 'invoice_id']],
        ])), true);

        expect($result['row_count'])->toBe(1);
    });

    it('does not reach another resource by naming it', function () {
        config()->set('ai-query-builder.resources', [InvoiceQuerySchema::class, ProductQuerySchema::class]);

        Product::create(['name' => 'Widget', 'type' => 'widget']);

        $result = json_decode(tool()->handle(new Request([
            'resource' => 'products',
            'select' => [['column' => 'product_name']],
        ])), true);

        expect($result['error'])->toBe('invalid_query_plan')
            ->and($result['errors'][0]['code'])->toBe('unknown_column');
    });

    it('applies mandatory scopes the model cannot see or remove', function () {
        Invoice::create(['tenant_id' => 1, 'issued_at' => '2026-02-01', 'total' => 1, 'status' => 'paid']);
        Invoice::create(['tenant_id' => 2, 'issued_at' => '2026-02-01', 'total' => 2, 'status' => 'paid']);

        $result = json_decode(tool()->handle(new Request([
            'select' => [['column' => 'invoice_id']],
        ])), true);

        expect($result['row_count'])->toBe(1);
    });
});
