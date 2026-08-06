<?php

declare(strict_types=1);

use JTMcC\AiQueryBuilder\Contract\SchemaContract;
use JTMcC\AiQueryBuilder\Schema\ColumnDefinition;
use JTMcC\AiQueryBuilder\Schema\ResourceSchema;
use JTMcC\AiQueryBuilder\Tests\Fixtures\InvoiceSchema;
use Workbench\App\Models\Invoice;
use Workbench\App\Models\User;

function contract(?object $user = null): SchemaContract
{
    return SchemaContract::for(InvoiceSchema::make(), $user);
}

describe('per-user visibility', function () {
    it('omits a gated column for a user who cannot see it', function () {
        expect(array_keys(contract()->columns()))->not->toContain('customer_notes');
    });

    it('includes a gated column for a user who can', function () {
        expect(array_keys(contract(new User)->columns()))->toContain('customer_notes');
    });

    it('keeps a gated column out of the json schema entirely', function () {
        expect(json_encode(contract()->toJsonSchema()))->not->toContain('customer_notes');
    });

    it('keeps a gated column out of the prompt entirely', function () {
        expect(contract()->toPrompt())->not->toContain('customer_notes');
    });
});

describe('data dictionary', function () {
    it('flattens relation columns into paths', function () {
        expect(array_keys(contract()->columns()))
            ->toContain('lines.quantity')
            ->toContain('lines.product.type');
    });

    it('reports each column capability', function () {
        $columns = contract()->toArray()['columns'];

        expect($columns['total'])->toBe([
            'type' => 'number',
            'unit' => 'currency:USD',
            'selectable' => true,
            'sortable' => true,
            'filters' => ['>', '<', 'between'],
            'aggregates' => ['sum', 'avg', 'min', 'max'],
        ])->and($columns['status']['values'])->toBe(['draft', 'sent', 'paid', 'void']);
    });

    it('marks a filter-only column as not selectable', function () {
        expect(contract()->toArray()['columns']['internal_margin'])
            ->not->toHaveKey('selectable');
    });

    it('reports the limits an agent must work within', function () {
        expect(contract()->toArray()['limits'])->toBe([
            'default_rows' => 100,
            'max_rows' => 1000,
            'max_relation_depth' => 2,
        ]);
    });
});

describe('prompt rendering', function () {
    it('names the resource and every visible column', function () {
        $prompt = contract()->toPrompt();

        expect($prompt)->toContain('Resource: invoices')
            ->toContain('Customer invoices, one row per invoice.')
            ->toContain('- lines.product.type')
            ->toContain('Anything not listed does not exist.');
    });

    it('spells out per-column capabilities the json schema cannot express', function () {
        $prompt = contract()->toPrompt();

        expect($prompt)->toContain('aggregate(sum avg min max)')
            ->toContain('unit: currency:USD')
            ->toContain('one of: widget, service')
            ->toContain('group by(day month year)');
    });

    it('states the type of a column whose filter values are constrained', function () {
        expect(contract()->toPrompt())->toContain('- issued_at — date. select, filter');
    });

    it('says nothing about the type of a column that cannot be filtered', function () {
        // invoice_id is an integer to the model, but nothing may filter on it,
        // so a type here is a token cost on every step for nothing.
        expect(contract()->toPrompt())->toContain("\n- invoice_id — select, sort\n");
    });

    it('states the date literal rule once, and only where a date can be filtered', function () {
        $prompt = contract()->toPrompt();

        expect(substr_count($prompt, 'a relative expression such as "now-30d" is not evaluated'))->toBe(1)
            ->and($prompt)->toContain('2026-07-07 09:30:00');
    });

    it('omits the date rule from a resource with no date filter', function () {
        $schema = ResourceSchema::make()
            ->for(Invoice::class)
            ->name('invoices')
            ->column('status', fn (ColumnDefinition $c) => $c->filterable(['=']));

        expect(SchemaContract::for($schema)->toPrompt())->not->toContain('now-30d');
    });

    it('states the row and depth limits', function () {
        expect(contract()->toPrompt())
            ->toContain('Returns 100 rows unless a limit is given, up to 1000')
            ->toContain('at most 2 relations');
    });
});

describe('json schema', function () {
    it('pins the resource so a plan cannot target another', function () {
        expect(contract()->toJsonSchema()['properties']['resource']['const'])->toBe('invoices');
    });

    it('restricts select columns to those declared', function () {
        $enum = contract()->toJsonSchema()['$defs']['select']['properties']['column']['enum'];

        expect($enum)->toContain('total')
            ->toContain('lines.product.type')
            ->not->toContain('customer_notes');
    });

    it('allows a filter-only column to be filtered but not selected', function () {
        $schema = contract()->toJsonSchema();

        expect($schema['$defs']['condition']['properties']['column']['enum'])->toContain('internal_margin')
            ->and($schema['$defs']['select']['properties']['column']['enum'])->not->toContain('internal_margin');
    });

    it('defines filter groups recursively so nesting is expressible', function () {
        $conditions = contract()->toJsonSchema()['$defs']['filter_group']['properties']['conditions'];

        expect($conditions['items']['anyOf'])->toBe([
            ['$ref' => '#/$defs/condition'],
            ['$ref' => '#/$defs/filter_group'],
        ]);
    });

    it('forbids unknown keys at every level', function () {
        $schema = contract()->toJsonSchema();

        expect($schema['additionalProperties'])->toBeFalse()
            ->and($schema['$defs']['select']['additionalProperties'])->toBeFalse()
            ->and($schema['$defs']['condition']['additionalProperties'])->toBeFalse();
    });

    it('caps the limit at the schema maximum', function () {
        expect(contract()->toJsonSchema()['properties']['limit']['maximum'])->toBe(1000);
    });

    it('constrains aliases to a safe identifier shape', function () {
        expect(contract()->toJsonSchema()['$defs']['select']['properties']['as']['pattern'])
            ->toBe('^[a-zA-Z_][a-zA-Z0-9_]*$');
    });
});
