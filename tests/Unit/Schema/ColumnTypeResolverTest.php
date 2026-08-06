<?php

declare(strict_types=1);

use JTMcC\AiQueryBuilder\Exceptions\SchemaDefinitionException;
use JTMcC\AiQueryBuilder\Schema\ColumnDefinition;
use JTMcC\AiQueryBuilder\Schema\Enums\ColumnType;
use JTMcC\AiQueryBuilder\Schema\RelationDefinition;
use JTMcC\AiQueryBuilder\Schema\ResourceSchema;
use JTMcC\AiQueryBuilder\Tests\Fixtures\InvoiceSchema;
use Workbench\App\Models\Invoice;

it('reads the type from the model casts', function () {
    // Invoice::casts() declares issued_at as a date and total as decimal:2.
    expect(InvoiceSchema::make()->typeOf('issued_at'))->toBe(ColumnType::Date)
        ->and(InvoiceSchema::make()->typeOf('total'))->toBe(ColumnType::Number);
});

it('reads the type through an aliased column', function () {
    // The alias is what the agent writes; the cast is on the real attribute.
    expect(InvoiceSchema::make()->typeOf('invoice_id'))->toBe(ColumnType::Integer);
});

it('follows relations to the model that owns the column', function () {
    $schema = ResourceSchema::make()
        ->for(Invoice::class)
        ->name('invoices')
        ->relation('lines', fn (RelationDefinition $r) => $r
            ->relation('product', fn (RelationDefinition $p) => $p
                ->column('created_at')));

    expect($schema->typeOf('lines.product.created_at'))->toBe(ColumnType::Datetime);
});

it('infers nothing for a column the model says nothing about', function () {
    expect(InvoiceSchema::make()->typeOf('lines.product.name'))->toBeNull();
});

it('prefers a declared type over the cast', function () {
    $schema = ResourceSchema::make()
        ->for(Invoice::class)
        ->name('invoices')
        ->column('issued_at', fn (ColumnDefinition $c) => $c->typed(ColumnType::Datetime));

    expect($schema->typeOf('issued_at'))->toBe(ColumnType::Datetime);
});

it('accepts a declared type as a string', function () {
    $column = (new ColumnDefinition('issued_at'))->typed('datetime');

    expect($column->type())->toBe(ColumnType::Datetime);
});

it('rejects a type it does not know at declaration time', function () {
    (new ColumnDefinition('issued_at'))->typed('timestamptz');
})->throws(SchemaDefinitionException::class, 'timestamptz');

it('infers nothing when the schema has no model', function () {
    $schema = ResourceSchema::make()->name('invoices')->column('issued_at');

    expect($schema->typeOf('issued_at'))->toBeNull();
});

it('infers nothing for a relation segment that is not a relation', function () {
    $schema = ResourceSchema::make()
        ->for(Invoice::class)
        ->name('invoices')
        // getTable() exists on the model but is not a relation, so following it
        // must yield nothing rather than blowing up mid-validation.
        ->relation('getTable', fn (RelationDefinition $r) => $r->column('issued_at'));

    expect($schema->typeOf('getTable.issued_at'))->toBeNull();
});

it('re-infers when the schema is pointed at another model', function () {
    $schema = ResourceSchema::make()->name('invoices')->column('issued_at');

    expect($schema->typeOf('issued_at'))->toBeNull()
        ->and($schema->for(Invoice::class)->typeOf('issued_at'))->toBe(ColumnType::Date);
});
