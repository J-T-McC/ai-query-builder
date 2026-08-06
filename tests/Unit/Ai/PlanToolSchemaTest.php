<?php

declare(strict_types=1);

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\ObjectType;
use JTMcC\AiQueryBuilder\Ai\PlanSchemaDetail;
use JTMcC\AiQueryBuilder\Ai\PlanToolSchema;
use JTMcC\AiQueryBuilder\Contract\SchemaContract;
use JTMcC\AiQueryBuilder\Schema\ColumnDefinition;
use JTMcC\AiQueryBuilder\Schema\ResourceSchema;
use JTMcC\AiQueryBuilder\Tests\Fixtures\InvoiceSchema;
use Workbench\App\Models\Invoice;

/**
 * @return array<string, mixed>
 */
function planSchema(
    ?ResourceSchema $resource = null,
    PlanSchemaDetail $detail = PlanSchemaDetail::Enumerated,
    int $depth = PlanToolSchema::DEFAULT_FILTER_DEPTH,
): array {
    $contract = SchemaContract::for($resource ?? InvoiceSchema::make());
    $properties = (new PlanToolSchema($contract, $depth, $detail))->build(new JsonSchemaTypeFactory);
    $composed = (new ObjectType($properties))->withoutAdditionalProperties()->toArray();

    return is_array($composed['properties'] ?? null) ? $composed['properties'] : [];
}

/**
 * A resource whose columns all share one capability profile, so the only thing
 * that varies between two of them is how many there are.
 */
function wideSchema(int $columns): ResourceSchema
{
    $schema = ResourceSchema::make()->for(Invoice::class)->name('invoices');

    foreach (range(1, $columns) as $index) {
        $schema->column('column_'.$index, fn (ColumnDefinition $c) => $c
            ->filterable(['=', 'in'])
            ->groupable()
            ->sortable());
    }

    return $schema;
}

describe('enumerated detail', function () {
    it('enumerates the columns a plan may reference', function () {
        $schema = planSchema();

        expect($schema['select']['items']['properties']['column']['enum'])->toContain('total')
            ->and($schema['filters']['properties']['conditions']['items']['anyOf'][0]['properties']['column']['enum'])
            ->toContain('internal_margin');
    });
});

describe('generic detail', function () {
    it('describes columns rather than enumerating them', function () {
        $schema = planSchema(detail: PlanSchemaDetail::Generic);
        $condition = $schema['filters']['properties']['conditions']['items']['anyOf'][0];

        expect($schema['select']['items']['properties']['column'])->not->toHaveKey('enum')
            ->and($condition['properties']['column'])->not->toHaveKey('enum')
            ->and($condition['properties']['operator'])->not->toHaveKey('enum')
            ->and($condition['properties']['column']['description'])->toContain('resource description');
    });

    it('keeps the operators that are not derived from the schema', function () {
        $schema = planSchema(detail: PlanSchemaDetail::Generic);

        expect($schema['filters']['properties']['operator']['enum'])->toBe(['and', 'or'])
            ->and($schema['sort']['items']['properties']['direction']['enum'])->toBe(['asc', 'desc']);
    });

    it('stops growing with the contract', function () {
        // The whole argument for this detail level: an enumerated schema is
        // O(columns), a generic one is flat. Same capabilities, 3 columns
        // against 60, byte for byte identical.
        $narrow = json_encode(planSchema(wideSchema(3), PlanSchemaDetail::Generic));
        $wide = json_encode(planSchema(wideSchema(60), PlanSchemaDetail::Generic));

        expect($wide)->toBe($narrow);
    });

    it('widens its lead over the enumerated schema as the contract grows', function () {
        $gap = fn (int $columns): int => strlen((string) json_encode(planSchema(wideSchema($columns))))
            - strlen((string) json_encode(planSchema(wideSchema($columns), PlanSchemaDetail::Generic)));

        // Small contracts barely benefit. That is the honest case for keeping
        // Enumerated as the default and reaching for Generic when it pays.
        expect($gap(60))->toBeGreaterThan($gap(3) * 10);
    });
});

describe('byte stability', function () {
    it('emits the same bytes from two separate builds', function (PlanSchemaDetail $detail) {
        // The tool payload is the front of the cached prefix, and a provider
        // serves that cache only on an exact match. A schema that varied
        // between builds would cost every consumer full price on every
        // request, with nothing failing to say so.
        expect(json_encode(planSchema(detail: $detail)))->toBe(json_encode(planSchema(detail: $detail)));
    })->with([PlanSchemaDetail::Enumerated, PlanSchemaDetail::Generic]);
});
