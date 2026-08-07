<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Foundation\Testing\RefreshDatabase;
use JTMcC\AiQueryBuilder\Exceptions\CompilationException;
use JTMcC\AiQueryBuilder\Schema\ColumnDefinition;
use JTMcC\AiQueryBuilder\Schema\PivotDefinition;
use JTMcC\AiQueryBuilder\Schema\RelationDefinition;
use JTMcC\AiQueryBuilder\Schema\ResourceSchema;
use JTMcC\AiQueryBuilder\Tests\Fixtures\InvoiceSchema;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Invoice;
use Workbench\App\Models\InvoiceLine;
use Workbench\App\Models\Note;
use Workbench\App\Models\Product;
use Workbench\App\Models\Tag;

uses(RefreshDatabase::class);

/**
 * The invoice schema plus a tenant scope that no plan can express.
 */
function scopedSchema(): ResourceSchema
{
    return InvoiceSchema::make()
        ->alwaysScope(fn (Builder $query) => $query->where('invoices.tenant_id', 1));
}

/**
 * The scoped schema with a countable key on the far side of the pivot.
 *
 * Declared here rather than in the fixture so the shared contract tests are
 * not asked to absorb a capability only the many-to-many tests need.
 */
function taggedSchema(): ResourceSchema
{
    $schema = scopedSchema();

    $schema->findRelation('tags')?->column('id', fn (ColumnDefinition $c) => $c->aggregatable(['count']));

    return $schema;
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
            ->toContain('left join "invoice_lines" as "lines" on "invoices"."id" = "lines"."invoice_id"')
            // Products do not soft delete, so that join carries the key alone.
            ->toContain('left join "products" as "lines__product" on "lines"."product_id" = "lines__product"."id"');
    });

    it('joins a parent relation before its child', function () {
        $sql = compilePlan(['select' => [['column' => 'lines.product.name']]])->toSql();

        expect(strpos($sql, 'left join "invoice_lines"'))
            ->toBeLessThan(strpos($sql, 'left join "products"'));
    });

    it('applies a relation scope as an on condition rather than a where', function () {
        $schema = InvoiceSchema::make();
        $schema->findRelation('lines.product')?->alwaysScope(
            fn (JoinClause $join, ?Authenticatable $user, string $alias) => $join->where("{$alias}.type", '=', 'widget'),
        );

        $sql = compilePlan(['select' => [['column' => 'lines.product.name']]], $schema)->toSql();

        expect($sql)->toContain(
            'left join "products" as "lines__product" on "lines"."product_id" = "lines__product"."id" '.
            'and "lines__product"."type" = ?',
        );
    });
});

describe('join aliases', function () {
    it('aliases every joined table to its relation path', function () {
        $sql = compilePlan(['select' => [['column' => 'lines.product.name']]])->toSql();

        expect($sql)->toContain('"invoice_lines" as "lines"')
            ->toContain('"products" as "lines__product"');
    });

    it('leaves the root table unaliased so a mandatory scope still resolves', function () {
        $sql = compilePlan(['select' => [['column' => 'lines.quantity']]], scopedSchema())->toSql();

        expect($sql)->toContain('from "invoices" ')
            ->toContain('"invoices"."tenant_id" = ?');
    });

    it('joins the same table twice under distinct aliases', function () {
        // Two paths reaching `products`. Without aliases this compiles to two
        // joins of the same table and the column references are ambiguous.
        $schema = ResourceSchema::make()
            ->for(InvoiceLine::class)
            ->name('lines')
            ->maxRelationDepth(3)
            ->column('quantity')
            ->relation('product', fn (RelationDefinition $r) => $r->column('name'))
            ->relation('invoice', fn (RelationDefinition $i) => $i
                ->relation('lines', fn (RelationDefinition $l) => $l
                    ->relation('product', fn (RelationDefinition $p) => $p->column('name'))));

        $sql = compilePlan([
            'select' => [
                ['column' => 'product.name', 'as' => 'mine'],
                ['column' => 'invoice.lines.product.name', 'as' => 'sibling'],
            ],
        ], $schema)->toSql();

        expect($sql)->toContain('"products" as "product"')
            ->toContain('"products" as "invoice__lines__product"')
            ->toContain('"product"."name" as "mine"')
            ->toContain('"invoice__lines__product"."name" as "sibling"');
    });

    it('executes a query that joins the same table twice', function () {
        $widget = Product::create(['name' => 'Widget', 'type' => 'widget']);
        $service = Product::create(['name' => 'Support', 'type' => 'service']);

        $invoice = Invoice::create([
            'tenant_id' => 1, 'issued_at' => '2026-02-01', 'total' => 10, 'status' => 'paid',
        ]);

        $line = InvoiceLine::create([
            'invoice_id' => $invoice->id, 'product_id' => $widget->id, 'quantity' => 1,
        ]);

        InvoiceLine::create(['invoice_id' => $invoice->id, 'product_id' => $service->id, 'quantity' => 1]);

        $schema = ResourceSchema::make()
            ->for(InvoiceLine::class)
            ->name('lines')
            ->maxRelationDepth(3)
            ->column('id', fn (ColumnDefinition $c) => $c->as('line_id')->filterable(['=']))
            ->relation('product', fn (RelationDefinition $r) => $r->column('name'))
            ->relation('invoice', fn (RelationDefinition $i) => $i
                ->relation('lines', fn (RelationDefinition $l) => $l
                    ->relation('product', fn (RelationDefinition $p) => $p->column('name'))));

        $rows = compilePlan([
            'select' => [
                ['column' => 'product.name', 'as' => 'mine'],
                ['column' => 'invoice.lines.product.name', 'as' => 'sibling'],
            ],
            'filters' => [
                'operator' => 'and',
                'conditions' => [['column' => 'line_id', 'operator' => '=', 'value' => $line->id]],
            ],
        ], $schema)->get();

        // The line's own product on one side, every product on the invoice on
        // the other. Reaching two different rows of one table is the point.
        expect($rows->pluck('sibling', 'mine')->all())->toBe(['Widget' => 'Support']);
    });

    it('qualifies a relation scope with the alias when the same table is joined twice', function () {
        $schema = ResourceSchema::make()
            ->for(InvoiceLine::class)
            ->name('lines')
            ->column('quantity')
            ->relation('product', fn (RelationDefinition $r) => $r
                ->column('name')
                ->alwaysScope(fn (JoinClause $join, ?Authenticatable $user, string $alias) => $join
                    ->where("{$alias}.type", '=', 'widget')));

        $sql = compilePlan(['select' => [['column' => 'product.name']]], $schema)->toSql();

        expect($sql)->toContain('and "product"."type" = ?');
    });
});

describe('soft deletes on joins', function () {
    it('excludes soft deleted rows from a joined relation', function () {
        $sql = compilePlan(['select' => [['column' => 'lines.quantity']]])->toSql();

        expect($sql)->toContain(
            'left join "invoice_lines" as "lines" on "invoices"."id" = "lines"."invoice_id" '.
            'and "lines"."archived_at" is null',
        );
    });

    it('reads the deleted at column from the model rather than assuming a name', function () {
        // InvoiceLine renames it through DELETED_AT. A compiler that hardcoded
        // the default would still pass the test above on a conventional model.
        $sql = compilePlan(['select' => [['column' => 'lines.quantity']]])->toSql();

        expect($sql)->toContain('"lines"."archived_at" is null')
            ->and($sql)->not->toContain('"lines"."deleted_at"');
    });

    it('adds no condition for a relation whose model does not soft delete', function () {
        $sql = compilePlan(['select' => [['column' => 'lines.product.name']]])->toSql();

        // The negative lookahead is the assertion: the products join carries the
        // key condition and nothing appended after it.
        expect($sql)->toMatch(
            '/left join "products" as "lines__product" on "lines"\."product_id" = "lines__product"\."id"(?! and)/',
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

        expect($sql)->not->toContain('"lines"."archived_at" is null');
    });

    it('applies both the soft delete condition and a relation scope to the same join', function () {
        $schema = InvoiceSchema::make();
        $schema->findRelation('lines')?->alwaysScope(
            fn (JoinClause $join, ?Authenticatable $user, string $alias) => $join
                ->where("{$alias}.quantity", '>', 0),
        );

        $sql = compilePlan(['select' => [['column' => 'lines.quantity']]], $schema)->toSql();

        expect($sql)->toContain(
            'and "lines"."archived_at" is null and "lines"."quantity" > ?',
        );
    });

    it('does not add a second condition for a soft deleting root model', function () {
        $sql = compilePlan(['select' => [['column' => 'invoice_id']]])->toSql();

        // The root's exclusion comes from the model's global scope, in the
        // WHERE clause. The join pass must not restate it.
        expect(substr_count($sql, '"invoices"."deleted_at" is null'))->toBe(1);
    });
});

describe('many-to-many', function () {
    it('joins through the pivot table', function () {
        $sql = compilePlan(['select' => [['column' => 'tags.name']]])->toSql();

        expect($sql)
            ->toContain('left join "invoice_tag" as "tags__pivot" on "invoices"."id" = "tags__pivot"."invoice_id"')
            ->toContain('left join "tags" as "tags" on "tags__pivot"."tag_id" = "tags"."id"');
    });

    it('joins the pivot before the table hanging off it', function () {
        $sql = compilePlan(['select' => [['column' => 'tags.name']]])->toSql();

        expect(strpos($sql, '"invoice_tag" as "tags__pivot"'))
            ->toBeLessThan(strpos($sql, '"tags" as "tags"'));
    });

    it('applies soft deletes to the related table of a many-to-many', function () {
        $sql = compilePlan(['select' => [['column' => 'tags.name']]])->toSql();

        expect($sql)->toContain('"tags__pivot"."tag_id" = "tags"."id" and "tags"."deleted_at" is null');
    });

    it('refuses to aggregate a parent column across a many-to-many join', function () {
        compilePlan([
            'select' => [
                ['column' => 'tags.name'],
                ['column' => 'total', 'function' => 'sum'],
            ],
            'group_by' => [['column' => 'tags.name']],
        ]);
    })->throws(CompilationException::class, 'inflate the result');

    it('returns one row per link, not per invoice', function () {
        $invoice = Invoice::create([
            'tenant_id' => 1, 'issued_at' => '2026-02-01', 'total' => 10, 'status' => 'paid',
        ]);

        $urgent = Tag::create(['name' => 'urgent']);
        $review = Tag::create(['name' => 'review']);

        $invoice->tags()->attach([$urgent->id, $review->id]);

        $rows = compilePlan([
            'select' => [['column' => 'invoice_id'], ['column' => 'tags.name', 'as' => 'tag']],
            'sort' => [['column' => 'tags.name', 'direction' => 'asc']],
        ], scopedSchema())->get();

        expect($rows->pluck('tag')->all())->toBe(['review', 'urgent']);
    });

    it('counts across the link without inflating the parent', function () {
        $first = Invoice::create([
            'tenant_id' => 1, 'issued_at' => '2026-02-01', 'total' => 10, 'status' => 'paid',
        ]);

        $second = Invoice::create([
            'tenant_id' => 1, 'issued_at' => '2026-02-02', 'total' => 20, 'status' => 'paid',
        ]);

        $urgent = Tag::create(['name' => 'urgent']);

        $first->tags()->attach($urgent->id);
        $second->tags()->attach($urgent->id);

        $rows = compilePlan([
            'select' => [
                ['column' => 'tags.name', 'as' => 'tag'],
                ['column' => 'tags.id', 'function' => 'count', 'as' => 'uses'],
            ],
            'group_by' => [['column' => 'tags.name']],
        ], taggedSchema())->get();

        expect($rows->pluck('uses', 'tag')->all())->toBe(['urgent' => 2]);
    });

    it('excludes a soft deleted row on the far side of the pivot', function () {
        $invoice = Invoice::create([
            'tenant_id' => 1, 'issued_at' => '2026-02-01', 'total' => 10, 'status' => 'paid',
        ]);

        $kept = Tag::create(['name' => 'urgent']);
        $gone = Tag::create(['name' => 'obsolete']);

        $invoice->tags()->attach([$kept->id, $gone->id]);
        $gone->delete();

        $rows = compilePlan([
            'select' => [['column' => 'tags.name', 'as' => 'tag']],
            'filters' => [
                'operator' => 'and',
                'conditions' => [['column' => 'tags.name', 'operator' => 'in', 'value' => ['urgent', 'obsolete']]],
            ],
        ], scopedSchema())->get();

        expect($rows->pluck('tag')->all())->toBe(['urgent']);
    });
});

describe('pivot scopes', function () {
    it('applies a pivot scope to the pivot join and not the related join', function () {
        $schema = InvoiceSchema::make();
        $schema->findRelation('tags')?->alwaysPivotScope(
            fn (JoinClause $join, ?Authenticatable $user, string $alias) => $join->whereNull("{$alias}.revoked_at"),
        );

        $sql = compilePlan(['select' => [['column' => 'tags.name']]], $schema)->toSql();

        expect($sql)->toContain(
            'on "invoices"."id" = "tags__pivot"."invoice_id" and "tags__pivot"."revoked_at" is null',
        )->and($sql)->not->toContain('"tags"."revoked_at"');
    });

    it('applies a relation scope to the related join and not the pivot join', function () {
        $schema = InvoiceSchema::make();
        $schema->findRelation('tags')?->alwaysScope(
            fn (JoinClause $join, ?Authenticatable $user, string $alias) => $join->where("{$alias}.name", '!=', 'draft'),
        );

        $sql = compilePlan(['select' => [['column' => 'tags.name']]], $schema)->toSql();

        expect($sql)->toContain('"tags__pivot"."tag_id" = "tags"."id" and "tags"."deleted_at" is null and "tags"."name" != ?')
            ->and($sql)->not->toContain('"tags__pivot"."name"');
    });

    it('keeps a revoked link out of the results', function () {
        $invoice = Invoice::create([
            'tenant_id' => 1, 'issued_at' => '2026-02-01', 'total' => 10, 'status' => 'paid',
        ]);

        $kept = Tag::create(['name' => 'urgent']);
        $revoked = Tag::create(['name' => 'stale']);

        $invoice->tags()->attach($kept->id);
        $invoice->tags()->attach($revoked->id, ['revoked_at' => '2026-02-02 00:00:00']);

        $schema = scopedSchema();
        $schema->findRelation('tags')?->alwaysPivotScope(
            fn (JoinClause $join, ?Authenticatable $user, string $alias) => $join->whereNull("{$alias}.revoked_at"),
        );

        $rows = compilePlan([
            'select' => [['column' => 'tags.name', 'as' => 'tag']],
            'filters' => [
                'operator' => 'and',
                'conditions' => [['column' => 'tags.name', 'operator' => 'in', 'value' => ['urgent', 'stale']]],
            ],
        ], $schema)->get();

        expect($rows->pluck('tag')->all())->toBe(['urgent']);
    });

    it('ignores a pivot scope on a relation that has no pivot', function () {
        $schema = InvoiceSchema::make();
        $schema->findRelation('lines')?->alwaysPivotScope(
            fn (JoinClause $join, ?Authenticatable $user, string $alias) => $join->whereNull("{$alias}.nonexistent"),
        );

        $sql = compilePlan(['select' => [['column' => 'lines.quantity']]], $schema)->toSql();

        expect($sql)->not->toContain('nonexistent');
    });
});

describe('pivot columns', function () {
    it('qualifies a pivot column against the pivot table', function () {
        $sql = compilePlan(['select' => [['column' => 'tags.pivot.assigned_at']]])->toSql();

        expect($sql)->toContain('"tags__pivot"."assigned_at" as "tags_pivot_assigned_at"');
    });

    it('joins the pivot for a plan that reads only a pivot column', function () {
        // No column from `tags` itself, so the pivot alias has to be registered
        // by the join pass rather than incidentally by another clause.
        $sql = compilePlan(['select' => [['column' => 'tags.pivot.assigned_at']]])->toSql();

        expect($sql)->toContain('"invoice_tag" as "tags__pivot"')
            ->toContain('"tags" as "tags"');
    });

    it('does not treat the pivot segment as a relation to join', function () {
        $sql = compilePlan(['select' => [['column' => 'tags.pivot.assigned_at']]])->toSql();

        expect(substr_count($sql, '"invoice_tag"'))->toBe(1);
    });

    it('filters on a pivot column', function () {
        $invoice = Invoice::create([
            'tenant_id' => 1, 'issued_at' => '2026-02-01', 'total' => 10, 'status' => 'paid',
        ]);

        $early = Tag::create(['name' => 'early']);
        $late = Tag::create(['name' => 'late']);

        $invoice->tags()->attach($early->id, ['assigned_at' => '2026-01-05']);
        $invoice->tags()->attach($late->id, ['assigned_at' => '2026-06-05']);

        $rows = compilePlan([
            'select' => [['column' => 'tags.name', 'as' => 'tag']],
            'filters' => [
                'operator' => 'and',
                'conditions' => [[
                    'column' => 'tags.pivot.assigned_at',
                    'operator' => 'between',
                    'value' => ['2026-01-01', '2026-01-31'],
                ]],
            ],
        ], scopedSchema())->get();

        expect($rows->pluck('tag')->all())->toBe(['early']);
    });

    it('reads a pivot column and a related column in one plan', function () {
        $invoice = Invoice::create([
            'tenant_id' => 1, 'issued_at' => '2026-02-01', 'total' => 10, 'status' => 'paid',
        ]);

        $tag = Tag::create(['name' => 'urgent']);
        $invoice->tags()->attach($tag->id, ['assigned_at' => '2026-01-05']);

        $rows = compilePlan([
            'select' => [
                ['column' => 'tags.name', 'as' => 'tag'],
                ['column' => 'tags.pivot.assigned_at', 'as' => 'assigned'],
            ],
        ], scopedSchema())->get();

        expect($rows->first()->tag)->toBe('urgent')
            ->and($rows->first()->assigned)->toBe('2026-01-05');
    });

    it('keeps a pivot column distinct from a related column of the same name', function () {
        // Both tables have a `name`. The pivot segment is what stops the two
        // from competing for `tags.name`.
        $schema = scopedSchema();
        $schema->findRelation('tags')?->pivot(
            fn (PivotDefinition $p) => $p->column('tag_id', fn (ColumnDefinition $c) => $c->as('name')),
        );

        $sql = compilePlan([
            'select' => [
                ['column' => 'tags.name', 'as' => 'related'],
                ['column' => 'tags.pivot.name', 'as' => 'link'],
            ],
        ], $schema)->toSql();

        expect($sql)->toContain('"tags"."name" as "related"')
            ->toContain('"tags__pivot"."tag_id" as "link"');
    });

    it('allows aggregating a pivot column across the many-to-many', function () {
        $schema = scopedSchema();
        $schema->findRelation('tags')?->pivot(
            fn (PivotDefinition $p) => $p->column('id', fn (ColumnDefinition $c) => $c
                ->as('link_id')
                ->aggregatable(['count'])),
        );

        $sql = compilePlan([
            'select' => [['column' => 'tags.pivot.link_id', 'function' => 'count', 'as' => 'links']],
        ], $schema)->toSql();

        // The pivot sits on the to-many side, so this is not a fan-out risk.
        expect($sql)->toContain('COUNT("tags__pivot"."id") as "links"');
    });
});

describe('polymorphic relations', function () {
    $noted = fn (): ResourceSchema => scopedSchema()
        ->relation('notes', fn (RelationDefinition $r) => $r
            ->column('body', fn (ColumnDefinition $c) => $c->filterable(['='])->groupable()->sortable()));

    $morphTagged = fn (): ResourceSchema => scopedSchema()
        ->relation('morphTags', fn (RelationDefinition $r) => $r
            ->column('name', fn (ColumnDefinition $c) => $c->filterable(['=', 'in'])->sortable()));

    it('constrains a morph many by its type', function () use ($noted) {
        $sql = compilePlan(['select' => [['column' => 'notes.body']]], $noted())->toSql();

        expect($sql)->toContain('"invoices"."id" = "notes"."notable_id" and "notes"."notable_type" = ?');
    });

    it('does not read another parent type through a morph many', function () use ($noted) {
        $invoice = Invoice::create([
            'tenant_id' => 1, 'issued_at' => '2026-02-01', 'total' => 10, 'status' => 'paid',
        ]);

        $product = Product::create(['name' => 'Widget', 'type' => 'widget']);

        $invoice->notes()->create(['body' => 'about the invoice']);

        // Same notes table, same id, different parent type. Without the morph
        // condition the invoice would pick this up as one of its own.
        $product->notes()->create(['body' => 'about the product']);

        $rows = compilePlan([
            'select' => [['column' => 'notes.body', 'as' => 'note']],
        ], $noted())->get();

        expect($rows->pluck('note')->all())->toBe(['about the invoice']);
    });

    it('constrains a morph to many by the type on its pivot', function () use ($morphTagged) {
        $sql = compilePlan(['select' => [['column' => 'morphTags.name']]], $morphTagged())->toSql();

        expect($sql)->toContain(
            '"invoices"."id" = "morphTags__pivot"."taggable_id" '.
            'and "morphTags__pivot"."taggable_type" = ?',
        );
    });

    it('does not read another parent type through a morph to many', function () use ($morphTagged) {
        $invoice = Invoice::create([
            'tenant_id' => 1, 'issued_at' => '2026-02-01', 'total' => 10, 'status' => 'paid',
        ]);

        $product = Product::create(['name' => 'Widget', 'type' => 'widget']);

        $mine = Tag::create(['name' => 'mine']);
        $theirs = Tag::create(['name' => 'theirs']);

        $invoice->morphTags()->attach($mine->id);
        $product->morphTags()->attach($theirs->id);

        $rows = compilePlan([
            'select' => [['column' => 'morphTags.name', 'as' => 'tag']],
        ], $morphTagged())->get();

        expect($rows->pluck('tag')->all())->toBe(['mine']);
    });

    it('treats a morph many as to-many for fan-out', function () use ($noted) {
        // An invoice with three notes would be summed three times.
        compilePlan([
            'select' => [
                ['column' => 'notes.body'],
                ['column' => 'total', 'function' => 'sum'],
            ],
            'group_by' => [['column' => 'notes.body']],
        ], $noted());
    })->throws(CompilationException::class, 'inflate the result');

    it('refuses a morph to, which has no table to join', function () {
        $schema = ResourceSchema::make()
            ->for(Note::class)
            ->name('notes')
            ->column('body')
            ->relation('notable', fn (RelationDefinition $r) => $r->column('id'));

        compilePlan(['select' => [['column' => 'notable.id']]], $schema);
    })->throws(CompilationException::class, 'stored per row rather than fixed by the schema');

    it('names the relation type it refused', function () {
        $schema = ResourceSchema::make()
            ->for(Note::class)
            ->name('notes')
            ->column('body')
            ->relation('notable', fn (RelationDefinition $r) => $r->column('id'));

        expect(fn () => compilePlan(['select' => [['column' => 'notable.id']]], $schema))
            ->toThrow(CompilationException::class, 'The relation [notable] is a MorphTo');
    });
});

describe('through relations', function () {
    $customers = fn (): ResourceSchema => ResourceSchema::make()
        ->for(Customer::class)
        ->name('customers')
        ->column('id', fn (ColumnDefinition $c) => $c->as('customer_id')->aggregatable(['count'])->sortable())
        ->column('name', fn (ColumnDefinition $c) => $c->filterable(['='])->groupable()->sortable())
        ->relation('lines', fn (RelationDefinition $r) => $r
            ->column('quantity', fn (ColumnDefinition $c) => $c->aggregatable(['sum'])->groupable()->sortable()))
        ->relation('firstLine', fn (RelationDefinition $r) => $r
            ->column('quantity', fn (ColumnDefinition $c) => $c->aggregatable(['sum'])));

    it('joins the intermediate table then the far one', function () use ($customers) {
        $sql = compilePlan(['select' => [['column' => 'lines.quantity']]], $customers())->toSql();

        expect($sql)
            ->toContain('left join "invoices" as "lines__through" on "customers"."id" = "lines__through"."customer_id"')
            ->toContain('left join "invoice_lines" as "lines" on "lines__through"."id" = "lines"."invoice_id"');
    });

    it('applies the intermediate model soft deletes to the intermediate join', function () use ($customers) {
        // Invoices soft delete. A line hanging off a deleted invoice must not be
        // reachable through it, and the condition belongs on the join that
        // reaches the invoice, not the one that reaches the line.
        $sql = compilePlan(['select' => [['column' => 'lines.quantity']]], $customers())->toSql();

        expect($sql)->toContain('"customers"."id" = "lines__through"."customer_id" '.
            'and "lines__through"."deleted_at" is null');
    });

    it('does not reach a row through a deleted intermediate', function () use ($customers) {
        $customer = Customer::create(['name' => 'Acme']);
        $product = Product::create(['name' => 'Widget', 'type' => 'widget']);

        $kept = Invoice::create([
            'tenant_id' => 1, 'customer_id' => $customer->id,
            'issued_at' => '2026-02-01', 'total' => 10, 'status' => 'paid',
        ]);

        $deleted = Invoice::create([
            'tenant_id' => 1, 'customer_id' => $customer->id,
            'issued_at' => '2026-02-02', 'total' => 20, 'status' => 'paid',
        ]);

        InvoiceLine::create(['invoice_id' => $kept->id, 'product_id' => $product->id, 'quantity' => 2]);
        InvoiceLine::create(['invoice_id' => $deleted->id, 'product_id' => $product->id, 'quantity' => 40]);

        $deleted->delete();

        $rows = compilePlan([
            'select' => [['column' => 'lines.quantity', 'function' => 'sum', 'as' => 'total_qty']],
        ], $customers())->get();

        expect($rows->first()->total_qty)->toBe(2);
    });

    it('treats a has many through as to-many for fan-out', function () use ($customers) {
        // Counting customers while joining to their lines counts each customer
        // once per line, two joins away.
        compilePlan([
            'select' => [
                ['column' => 'lines.quantity'],
                ['column' => 'customer_id', 'function' => 'count'],
            ],
            'group_by' => [['column' => 'lines.quantity']],
        ], $customers());
    })->throws(CompilationException::class, 'inflate the result');

    it('does not treat a has one through as to-many', function () use ($customers) {
        $sql = compilePlan([
            'select' => [['column' => 'firstLine.quantity', 'function' => 'sum']],
        ], $customers())->toSql();

        expect($sql)->toContain('SUM("firstLine"."quantity")');
    });
});

describe('projection', function () {
    it('compiles aggregates with their alias', function () {
        $sql = compilePlan([
            'select' => [['column' => 'lines.quantity', 'function' => 'sum']],
        ])->toSql();

        expect($sql)->toContain('SUM("lines"."quantity") as "sum_lines_quantity"');
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

        expect($sql)->toContain('SUM("lines"."quantity")');
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
