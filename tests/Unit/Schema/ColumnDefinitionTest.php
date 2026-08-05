<?php

declare(strict_types=1);

use AiQueryBuilder\AiQueryBuilder\Exceptions\SchemaDefinitionException;
use AiQueryBuilder\AiQueryBuilder\Schema\ColumnDefinition;
use AiQueryBuilder\AiQueryBuilder\Schema\Enums\Aggregate;
use AiQueryBuilder\AiQueryBuilder\Schema\Enums\DateBucket;
use AiQueryBuilder\AiQueryBuilder\Schema\Enums\Operator;
use Workbench\App\Models\User;

it('exposes the column name when no alias is set', function () {
    $column = new ColumnDefinition('total');

    expect($column->name())->toBe('total')
        ->and($column->exposedName())->toBe('total');
});

it('exposes the alias when one is set', function () {
    $column = (new ColumnDefinition('id'))->as('invoice_id');

    expect($column->name())->toBe('id')
        ->and($column->exposedName())->toBe('invoice_id');
});

it('denies every capability by default', function () {
    $column = new ColumnDefinition('total');

    expect($column->isSelectable())->toBeTrue()
        ->and($column->isFilterable())->toBeFalse()
        ->and($column->isSortable())->toBeFalse()
        ->and($column->isGroupable())->toBeFalse()
        ->and($column->operators())->toBe([])
        ->and($column->aggregates())->toBe([])
        ->and($column->dateBuckets())->toBe([]);
});

it('can be declared filterable but not selectable', function () {
    $column = (new ColumnDefinition('internal_margin'))
        ->filterable(['>', '<'])
        ->selectable(false);

    expect($column->isSelectable())->toBeFalse()
        ->and($column->isFilterable())->toBeTrue();
});

it('normalizes filterable operators to enum cases', function () {
    $column = (new ColumnDefinition('issued_at'))->filterable(['=', Operator::Between]);

    expect($column->operators())->toBe([Operator::Equals, Operator::Between])
        ->and($column->allowsOperator('='))->toBeTrue()
        ->and($column->allowsOperator(Operator::Between))->toBeTrue()
        ->and($column->allowsOperator('like'))->toBeFalse();
});

it('normalizes aggregates to enum cases', function () {
    $column = (new ColumnDefinition('total'))->aggregatable(['sum', Aggregate::Avg]);

    expect($column->aggregates())->toBe([Aggregate::Sum, Aggregate::Avg])
        ->and($column->allowsAggregate('sum'))->toBeTrue()
        ->and($column->allowsAggregate('count'))->toBeFalse();
});

it('marks a column groupable when date buckets are declared', function () {
    $column = (new ColumnDefinition('issued_at'))->groupableBy(['month', DateBucket::Year]);

    expect($column->isGroupable())->toBeTrue()
        ->and($column->dateBuckets())->toBe([DateBucket::Month, DateBucket::Year])
        ->and($column->allowsBucket('month'))->toBeTrue()
        ->and($column->allowsBucket('day'))->toBeFalse();
});

it('rejects unknown operators at definition time', function () {
    (new ColumnDefinition('total'))->filterable(['sqlmagic']);
})->throws(SchemaDefinitionException::class, 'sqlmagic');

it('rejects unknown aggregates at definition time', function () {
    (new ColumnDefinition('total'))->aggregatable(['median']);
})->throws(SchemaDefinitionException::class, 'median');

it('rejects unknown date buckets at definition time', function () {
    (new ColumnDefinition('issued_at'))->groupableBy(['fortnight']);
})->throws(SchemaDefinitionException::class, 'fortnight');

it('deduplicates repeated operators', function () {
    $column = (new ColumnDefinition('total'))->filterable(['>', '>', '<']);

    expect($column->operators())->toBe([Operator::GreaterThan, Operator::LessThan]);
});

it('records enum values and unit metadata', function () {
    $column = (new ColumnDefinition('status'))
        ->enum(['draft', 'paid'])
        ->measuredIn('currency:USD')
        ->describe('Invoice status');

    expect($column->enumValues())->toBe(['draft', 'paid'])
        ->and($column->unit())->toBe('currency:USD')
        ->and($column->description())->toBe('Invoice status');
});

it('is visible to everyone when no visibility rule is declared', function () {
    $column = new ColumnDefinition('total');

    expect($column->isVisibleTo(null))->toBeTrue()
        ->and($column->isVisibleTo(new User))->toBeTrue();
});

it('honours the visibility rule', function () {
    $column = (new ColumnDefinition('customer_notes'))
        ->visibleWhen(fn (?object $user): bool => $user !== null);

    expect($column->isVisibleTo(null))->toBeFalse()
        ->and($column->isVisibleTo(new User))->toBeTrue();
});
