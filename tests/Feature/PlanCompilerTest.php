<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Foundation\Testing\RefreshDatabase;
use JTMcC\AiQueryBuilder\Exceptions\CompilationException;
use JTMcC\AiQueryBuilder\Schema\ResourceSchema;
use JTMcC\AiQueryBuilder\Tests\Fixtures\InvoiceSchema;
use Workbench\App\Models\Invoice;
use Workbench\App\Models\InvoiceLine;
use Workbench\App\Models\Product;

uses(RefreshDatabase::class);

/**
 * The invoice schema plus a tenant scope that no plan can express.
 */
function scopedSchema(): ResourceSchema
{
    return InvoiceSchema::make()
        ->alwaysScope(fn (Builder $query) => $query->where('invoices.tenant_id', 1));
}

describe('mandatory scopes', function () {
    it('applies an always scope to every query', function () {
        $sql = compilePlan(['select' => [['column' => 'invoice_id']]], scopedSchema())->toSql();

        expect($sql)->toContain('"invoices"."tenant_id" = ?');
    });

    it('nests the agent filter tree so a top-level or cannot escape the scope', function () {
        $query = compilePlan([
            'select' => [['column' => 'invoice_id']],
            'filters' => [
                'operator' => 'or',
                'conditions' => [
                    ['column' => 'total', 'operator' => '>', 'value' => 100],
                    ['column' => 'total', 'operator' => '<', 'value' => 10],
                ],
            ],
        ], scopedSchema());

        // The parentheses here are the whole guarantee: without them this reads
        // as `tenant_id = ? and total > ? or total < ?`, which returns rows
        // belonging to every other tenant.
        expect($query->toSql())->toContain(
            'where "invoices"."tenant_id" = ? and ("invoices"."total" > ? or "invoices"."total" < ?)',
        );
    });

    it('does not leak other tenants when the agent uses a top-level or', function () {
        $mine = Invoice::create([
            'tenant_id' => 1, 'issued_at' => '2026-02-01', 'total' => 5, 'status' => 'paid',
        ]);

        Invoice::create([
            'tenant_id' => 2, 'issued_at' => '2026-02-01', 'total' => 5000, 'status' => 'paid',
        ]);

        $rows = compilePlan([
            'select' => [['column' => 'invoice_id']],
            'filters' => [
                'operator' => 'or',
                'conditions' => [
                    ['column' => 'total', 'operator' => '>', 'value' => 100],
                    ['column' => 'total', 'operator' => '<', 'value' => 10],
                ],
            ],
        ], scopedSchema())->get();

        expect($rows->pluck('invoice_id')->all())->toBe([$mine->id]);
    });

    it('keeps every value bound rather than interpolated', function () {
        // A free-text column, since an enum column would reject this at validation.
        $query = compilePlan([
            'select' => [['column' => 'invoice_id']],
            'filters' => [
                'operator' => 'and',
                'conditions' => [
                    ['column' => 'lines.product.name', 'operator' => 'like', 'value' => "x' or 1=1 --"],
                ],
            ],
        ], scopedSchema());

        expect($query->toSql())->not->toContain('1=1')
            ->and($query->getBindings())->toContain("x' or 1=1 --");
    });
});

describe('joins', function () {
    it('derives joins from declared relations', function () {
        $sql = compilePlan([
            'select' => [['column' => 'lines.product.name']],
        ])->toSql();

        expect($sql)
            ->toContain('left join "invoice_lines" on "invoices"."id" = "invoice_lines"."invoice_id"')
            // Products do not soft delete, so that join carries the key alone.
            ->toContain('left join "products" on "invoice_lines"."product_id" = "products"."id"');
    });

    it('joins a parent relation before its child', function () {
        $sql = compilePlan(['select' => [['column' => 'lines.product.name']]])->toSql();

        expect(strpos($sql, 'left join "invoice_lines"'))
            ->toBeLessThan(strpos($sql, 'left join "products"'));
    });

    it('applies a relation scope as an on condition rather than a where', function () {
        $schema = InvoiceSchema::make();
        $schema->findRelation('lines.product')?->alwaysScope(
            fn (JoinClause $join) => $join->where('products.type', '=', 'widget'),
        );

        $sql = compilePlan(['select' => [['column' => 'lines.product.name']]], $schema)->toSql();

        expect($sql)->toContain(
            'left join "products" on "invoice_lines"."product_id" = "products"."id" and "products"."type" = ?',
        );
    });
});

describe('soft deletes on joins', function () {
    it('excludes soft deleted rows from a joined relation', function () {
        $sql = compilePlan(['select' => [['column' => 'lines.quantity']]])->toSql();

        expect($sql)->toContain(
            'left join "invoice_lines" on "invoices"."id" = "invoice_lines"."invoice_id" '.
            'and "invoice_lines"."archived_at" is null',
        );
    });

    it('reads the deleted at column from the model rather than assuming a name', function () {
        // InvoiceLine renames it through DELETED_AT. A compiler that hardcoded
        // the default would still pass the test above on a conventional model.
        $sql = compilePlan(['select' => [['column' => 'lines.quantity']]])->toSql();

        expect($sql)->toContain('"invoice_lines"."archived_at" is null')
            ->and($sql)->not->toContain('"invoice_lines"."deleted_at"');
    });

    it('adds no condition for a relation whose model does not soft delete', function () {
        $sql = compilePlan(['select' => [['column' => 'lines.product.name']]])->toSql();

        // The negative lookahead is the assertion: the products join carries the
        // key condition and nothing appended after it.
        expect($sql)->toMatch(
            '/left join "products" on "invoice_lines"\."product_id" = "products"\."id"(?! and)/',
        );
    });

    it('keeps the condition on the on clause so a left join stays a left join', function () {
        $invoice = Invoice::create([
            'tenant_id' => 1, 'issued_at' => '2026-02-01', 'total' => 10, 'status' => 'paid',
        ]);

        $product = Product::create(['name' => 'Widget', 'type' => 'widget']);

        InvoiceLine::create([
            'invoice_id' => $invoice->id, 'product_id' => $product->id, 'quantity' => 2,
        ])->delete();

        $rows = compilePlan([
            'select' => [['column' => 'invoice_id'], ['column' => 'lines.quantity']],
        ], scopedSchema())->get();

        // The invoice's only line is deleted. In the WHERE clause this condition
        // would drop the invoice entirely; on the ON clause the invoice stays
        // and the line reads as null, which is what "ignore deleted" means.
        expect($rows)->toHaveCount(1)
            ->and($rows->first()->invoice_id)->toBe($invoice->id)
            ->and($rows->first()->quantity)->toBeNull();
    });

    it('leaves a soft deleted row out of an aggregate over the relation', function () {
        $invoice = Invoice::create([
            'tenant_id' => 1, 'issued_at' => '2026-02-01', 'total' => 10, 'status' => 'paid',
        ]);

        $product = Product::create(['name' => 'Widget', 'type' => 'widget']);

        InvoiceLine::create(['invoice_id' => $invoice->id, 'product_id' => $product->id, 'quantity' => 2]);
        InvoiceLine::create(['invoice_id' => $invoice->id, 'product_id' => $product->id, 'quantity' => 40])->delete();

        $rows = compilePlan([
            'select' => [['column' => 'lines.quantity', 'function' => 'sum', 'as' => 'total_qty']],
        ], scopedSchema())->get();

        expect($rows->first()->total_qty)->toBe(2);
    });

    it('includes soft deleted rows when the relation opts in', function () {
        $schema = InvoiceSchema::make();
        $schema->findRelation('lines')?->withTrashed();

        $sql = compilePlan(['select' => [['column' => 'lines.quantity']]], $schema)->toSql();

        expect($sql)->not->toContain('"invoice_lines"."archived_at" is null');
    });

    it('applies both the soft delete condition and a relation scope to the same join', function () {
        $schema = InvoiceSchema::make();
        $schema->findRelation('lines')?->alwaysScope(
            fn (JoinClause $join) => $join->where('invoice_lines.quantity', '>', 0),
        );

        $sql = compilePlan(['select' => [['column' => 'lines.quantity']]], $schema)->toSql();

        expect($sql)->toContain(
            'and "invoice_lines"."archived_at" is null and "invoice_lines"."quantity" > ?',
        );
    });

    it('does not add a second condition for a soft deleting root model', function () {
        $sql = compilePlan(['select' => [['column' => 'invoice_id']]])->toSql();

        // The root's exclusion comes from the model's global scope, in the
        // WHERE clause. The join pass must not restate it.
        expect(substr_count($sql, '"invoices"."deleted_at" is null'))->toBe(1);
    });
});

describe('projection', function () {
    it('compiles aggregates with their alias', function () {
        $sql = compilePlan([
            'select' => [['column' => 'lines.quantity', 'function' => 'sum']],
        ])->toSql();

        expect($sql)->toContain('SUM("invoice_lines"."quantity") as "sum_lines_quantity"');
    });

    it('compiles a date bucket into the select and the group by', function () {
        $sql = compilePlan([
            'select' => [
                ['column' => 'issued_at'],
                ['column' => 'lines.quantity', 'function' => 'sum'],
            ],
            'group_by' => [['column' => 'issued_at', 'bucket' => 'month']],
        ])->toSql();

        expect($sql)->toContain('strftime(\'%Y-%m\', "invoices"."issued_at") as "issued_at"')
            ->toContain('group by strftime(\'%Y-%m\', "invoices"."issued_at")');
    });

    it('binds having values against the projected alias', function () {
        $query = compilePlan([
            'select' => [['column' => 'lines.quantity', 'function' => 'sum', 'as' => 'total_qty']],
            'having' => [['column' => 'total_qty', 'operator' => '>', 'value' => 5]],
        ]);

        expect($query->toSql())->toContain('having "total_qty" > ?')
            ->and($query->getBindings())->toContain(5);
    });

    it('sorts by a projected alias', function () {
        $sql = compilePlan([
            'select' => [['column' => 'lines.quantity', 'function' => 'sum', 'as' => 'total_qty']],
            'sort' => [['column' => 'total_qty', 'direction' => 'desc']],
        ])->toSql();

        expect($sql)->toContain('order by "total_qty" desc');
    });

    it('sorts by a qualified column when no alias matches', function () {
        $sql = compilePlan([
            'select' => [['column' => 'invoice_id']],
            'sort' => [['column' => 'issued_at', 'direction' => 'asc']],
        ])->toSql();

        expect($sql)->toContain('order by "invoices"."issued_at" asc');
    });

    it('applies the plan limit', function () {
        expect(compilePlan(['select' => [['column' => 'invoice_id']], 'limit' => 7])->toSql())
            ->toContain('limit 7');
    });
});

describe('fan-out protection', function () {
    it('refuses to aggregate a parent column across a to-many join', function () {
        compilePlan([
            'select' => [
                ['column' => 'lines.product.type'],
                ['column' => 'total', 'function' => 'sum'],
            ],
            'group_by' => [['column' => 'lines.product.type']],
        ]);
    })->throws(CompilationException::class, 'inflate the result');

    it('allows aggregating a column on the to-many side', function () {
        $sql = compilePlan([
            'select' => [
                ['column' => 'lines.product.type'],
                ['column' => 'lines.quantity', 'function' => 'sum'],
            ],
            'group_by' => [['column' => 'lines.product.type']],
        ])->toSql();

        expect($sql)->toContain('SUM("invoice_lines"."quantity")');
    });

    it('allows aggregating a parent column when no to-many relation is joined', function () {
        $sql = compilePlan(['select' => [['column' => 'total', 'function' => 'sum']]])->toSql();

        expect($sql)->toContain('SUM("invoices"."total")');
    });
});

describe('execution', function () {
    it('returns correct grouped totals against real rows', function () {
        $widget = Product::create(['name' => 'Widget', 'type' => 'widget']);
        $service = Product::create(['name' => 'Support', 'type' => 'service']);

        $invoice = Invoice::create([
            'tenant_id' => 1, 'issued_at' => '2026-02-01', 'total' => 300, 'status' => 'paid',
        ]);

        InvoiceLine::create(['invoice_id' => $invoice->id, 'product_id' => $widget->id, 'quantity' => 2]);
        InvoiceLine::create(['invoice_id' => $invoice->id, 'product_id' => $widget->id, 'quantity' => 3]);
        InvoiceLine::create(['invoice_id' => $invoice->id, 'product_id' => $service->id, 'quantity' => 4]);

        $rows = compilePlan([
            'select' => [
                ['column' => 'lines.product.type', 'as' => 'product_type'],
                ['column' => 'lines.quantity', 'function' => 'sum', 'as' => 'total_qty'],
            ],
            'filters' => [
                'operator' => 'and',
                'conditions' => [
                    ['column' => 'issued_at', 'operator' => 'between', 'value' => ['2026-01-01', '2026-03-31']],
                ],
            ],
            'group_by' => [['column' => 'lines.product.type']],
            'sort' => [['column' => 'total_qty', 'direction' => 'desc']],
        ], scopedSchema())->get();

        expect($rows->pluck('total_qty', 'product_type')->all())
            ->toBe(['widget' => 5, 'service' => 4]);
    });

    it('excludes soft deleted rows through the model global scope', function () {
        $kept = Invoice::create(['tenant_id' => 1, 'issued_at' => '2026-02-01', 'total' => 1, 'status' => 'paid']);
        Invoice::create(['tenant_id' => 1, 'issued_at' => '2026-02-01', 'total' => 2, 'status' => 'paid'])->delete();

        $rows = compilePlan(['select' => [['column' => 'invoice_id']]], scopedSchema())->get();

        expect($rows->pluck('invoice_id')->all())->toBe([$kept->id]);
    });
});
