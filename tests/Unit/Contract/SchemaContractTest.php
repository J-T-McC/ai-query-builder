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
            'unit' => 'currency:CAD',
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
            ->toContain('unit: currency:CAD')
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

    it('lists the named date ranges once, and only where one can be used', function () {
        $prompt = contract()->toPrompt();

        expect(substr_count($prompt, 'Date ranges: operator "within"'))->toBe(1)
            ->and($prompt)->toContain('last_month, this_quarter')
            ->toContain('last_<N>_<seconds|minutes|hours|days|weeks|months|years>')
            ->toContain('2026-07-07 09:30:00');
    });

    it('advertises within on the column that derives it', function () {
        expect(contract()->toPrompt())->toContain('filter(= > < >= <= between within)');
    });

    it('omits the date ranges from a resource with no date filter', function () {
        $schema = ResourceSchema::make()
            ->for(Invoice::class)
            ->name('invoices')
            ->column('status', fn (ColumnDefinition $c) => $c->filterable(['=']));

        expect(SchemaContract::for($schema)->toPrompt())->not->toContain('within');
    });

    it('states the row and depth limits', function () {
        expect(contract()->toPrompt())
            ->toContain('Returns 100 rows unless a limit is given, up to 1000')
            ->toContain('at most 2 relations');
    });

    it('repeats capabilities per column when a legend would cost more than it saves', function () {
        // Barely any two columns alike: the legend is a fixed cost with almost
        // nothing to amortise it over. Built here rather than taken from the
        // fixture, so adding a column there cannot silently flip the branch
        // this test is asserting.
        $schema = ResourceSchema::make()
            ->for(Invoice::class)
            ->name('invoices')
            ->column('id', fn (ColumnDefinition $c) => $c->sortable())
            ->column('issued_at', fn (ColumnDefinition $c) => $c->filterable(['>'])->groupableBy(['month']))
            ->column('total', fn (ColumnDefinition $c) => $c->aggregatable(['sum'])->sortable())
            ->column('status', fn (ColumnDefinition $c) => $c
                ->enum(['draft', 'sent', 'paid', 'void'])
                ->filterable(['=', 'in'])
                ->groupable());

        expect(SchemaContract::for($schema)->toPrompt())
            ->not->toContain('Unless a column lists its own')
            ->toContain('- status — one of: draft, sent, paid, void. select, filter(= in), group');
    });

    it('states a shared capability set once when that is shorter', function () {
        $schema = ResourceSchema::make()->for(Invoice::class)->name('invoices');

        foreach (range(1, 20) as $index) {
            $schema->column('column_'.$index, fn (ColumnDefinition $c) => $c->filterable(['=', 'in'])->sortable());
        }

        $schema->column('total', fn (ColumnDefinition $c) => $c->aggregatable(['sum']));

        $prompt = SchemaContract::for($schema)->toPrompt();

        expect($prompt)->toContain('Unless a column lists its own capabilities, it supports: select, filter(= in), sort.')
            // Stated in the legend and nowhere else.
            ->and(substr_count($prompt, 'select, filter(= in), sort'))->toBe(1)
            // A column matching it carries nothing but its name.
            ->and($prompt)->toContain("\n- column_7\n")
            // One that does not still says so.
            ->and($prompt)->toContain('- total — select, aggregate(sum)');
    });

    it('lists a pivot column under the pivot segment', function () {
        expect(contract()->toPrompt())->toContain('tags.pivot.assigned_at');
    });

    it('keeps a pivot column distinct from the related table in the dictionary', function () {
        expect(array_keys(contract()->columns()))
            ->toContain('tags.name')
            ->toContain('tags.pivot.assigned_at');
    });

    it('carries a declared pivot column type into the contract', function () {
        // The pivot has no model to infer from, so the declared type is the
        // only thing that can reach the agent.
        expect(contract()->toJsonSchema())->not->toBeEmpty()
            ->and(contract()->columns()['tags.pivot.assigned_at']->type()?->value)->toBe('date');
    });

    it('does not double the full stop when a description ends in one', function () {
        $schema = ResourceSchema::make()
            ->for(Invoice::class)
            ->name('invoices')
            ->column('id', fn (ColumnDefinition $c) => $c->describe('Primary key.')->sortable());

        expect(SchemaContract::for($schema)->toPrompt())
            ->toContain('Primary key. select, sort')
            ->not->toContain('..');
    });
});

describe('byte stability', function () {
    // A provider serves a cached prompt prefix only when it matches byte for
    // byte. The tool payload renders at the very front of that prefix, so if
    // two builds of the same contract ever differ — set iteration, hash order,
    // a timestamp — every consumer silently pays full price on every request
    // and nothing fails. These are the tests that would fail instead.

    it('renders the same prompt from two separate builds', function () {
        expect(SchemaContract::for(InvoiceSchema::make())->toPrompt())
            ->toBe(SchemaContract::for(InvoiceSchema::make())->toPrompt());
    });

    it('renders the same prompt for the same user twice', function () {
        expect(contract(new User)->toPrompt())->toBe(contract(new User)->toPrompt());
    });

    it('renders the same json schema from two separate builds', function () {
        expect(json_encode(SchemaContract::for(InvoiceSchema::make())->toJsonSchema()))
            ->toBe(json_encode(SchemaContract::for(InvoiceSchema::make())->toJsonSchema()));
    });

    it('picks the same column block every time when a legend is in play', function () {
        // columnBlock() measures two renderings and emits the shorter. A tie
        // broken inconsistently would flip the payload between requests.
        $build = function (): string {
            $schema = ResourceSchema::make()->for(Invoice::class)->name('invoices');

            foreach (range(1, 20) as $index) {
                $schema->column('column_'.$index, fn (ColumnDefinition $c) => $c->filterable(['=', 'in'])->sortable());
            }

            return SchemaContract::for($schema)->toPrompt();
        };

        expect($build())->toBe($build());
    });
});

describe('fingerprint', function () {
    it('is stable across builds, so the payload is cacheable', function () {
        expect(contract()->fingerprint())->toBe(contract()->fingerprint());
    });

    it('differs for a user who is shown more', function () {
        expect(contract()->fingerprint())->not->toBe(contract(new User)->fingerprint());
    });

    it('changes when the schema changes what an agent is told', function () {
        expect(SchemaContract::for(InvoiceSchema::make()->maxLimit(10))->fingerprint())
            ->not->toBe(contract()->fingerprint());
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
