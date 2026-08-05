<?php

declare(strict_types=1);

use JTMcC\AiQueryBuilder\Plan\Enums\LogicalOperator;
use JTMcC\AiQueryBuilder\Plan\Enums\SortDirection;
use JTMcC\AiQueryBuilder\Schema\Enums\Aggregate;
use JTMcC\AiQueryBuilder\Schema\Enums\DateBucket;
use JTMcC\AiQueryBuilder\Schema\Enums\Operator;

it('validates the worked example from the architecture plan', function () {
    $plan = validatePlan([
        'resource' => 'invoices',
        'select' => [
            ['column' => 'lines.product.type', 'as' => 'product_type'],
            ['column' => 'lines.quantity', 'function' => 'sum', 'as' => 'total_qty'],
        ],
        'filters' => [
            'operator' => 'and',
            'conditions' => [
                ['column' => 'issued_at', 'operator' => 'between', 'value' => ['2026-01-01', '2026-03-31']],
                ['column' => 'lines.product.type', 'operator' => '=', 'value' => 'widget'],
            ],
        ],
        'group_by' => [['column' => 'lines.product.type']],
        'having' => [['column' => 'total_qty', 'operator' => '>', 'value' => 0]],
        'sort' => [['column' => 'total_qty', 'direction' => 'desc']],
        'limit' => 50,
    ]);

    expect($plan->resource)->toBe('invoices')
        ->and($plan->select)->toHaveCount(2)
        ->and($plan->select[1]->function)->toBe(Aggregate::Sum)
        ->and($plan->select[1]->alias)->toBe('total_qty')
        ->and($plan->filters?->operator)->toBe(LogicalOperator::And)
        ->and($plan->filters?->conditions)->toHaveCount(2)
        ->and($plan->groupBy[0]->path)->toBe('lines.product.type')
        ->and($plan->having[0]->alias)->toBe('total_qty')
        ->and($plan->sort[0]->direction)->toBe(SortDirection::Desc)
        ->and($plan->sort[0]->isAlias())->toBeTrue()
        ->and($plan->limit)->toBe(50)
        ->and($plan->isAggregated())->toBeTrue();
});

it('carries the resolved column definition on every clause', function () {
    $plan = validatePlan(['select' => [['column' => 'total', 'function' => 'sum']]]);

    expect($plan->select[0]->column->name())->toBe('total')
        ->and($plan->select[0]->column->unit())->toBe('currency:USD');
});

it('derives aliases when none are given', function () {
    $plan = validatePlan([
        'select' => [
            ['column' => 'total', 'function' => 'sum'],
            ['column' => 'lines.product.name'],
        ],
        'group_by' => [['column' => 'lines.product.name']],
    ]);

    expect($plan->select[0]->alias)->toBe('sum_total')
        ->and($plan->select[1]->alias)->toBe('lines_product_name');
});

it('applies the schema default limit when none is given', function () {
    expect(validatePlan(['select' => [['column' => 'invoice_id']]])->limit)->toBe(100);
});

it('reports the relation paths the compiler must join', function () {
    $plan = validatePlan([
        'select' => [['column' => 'lines.product.name']],
        'filters' => [
            'operator' => 'and',
            'conditions' => [['column' => 'lines.product.type', 'operator' => '=', 'value' => 'widget']],
        ],
    ]);

    expect($plan->relationPaths())->toBe(['lines', 'lines.product']);
});

it('reports no relation paths for a flat plan', function () {
    expect(validatePlan(['select' => [['column' => 'invoice_id']]])->relationPaths())->toBe([]);
});

it('round-trips to the input shape for audit and replay', function () {
    $input = [
        'resource' => 'invoices',
        'select' => [['column' => 'total', 'function' => 'sum', 'as' => 'total_sum']],
        'filters' => [
            'operator' => 'or',
            'conditions' => [['column' => 'status', 'operator' => '=', 'value' => 'paid']],
        ],
        'group_by' => [['column' => 'issued_at', 'bucket' => 'month']],
        'having' => [['column' => 'total_sum', 'operator' => '>', 'value' => 10]],
        'sort' => [['column' => 'total_sum', 'direction' => 'desc']],
        'limit' => 25,
    ];

    expect(validatePlan($input)->toArray())->toBe($input);
});

it('accepts a date bucket the column permits', function () {
    $plan = validatePlan([
        'select' => [['column' => 'issued_at'], ['column' => 'total', 'function' => 'sum']],
        'group_by' => [['column' => 'issued_at', 'bucket' => 'month']],
    ]);

    expect($plan->groupBy[0]->bucket)->toBe(DateBucket::Month);
});

it('accepts nested filter groups', function () {
    $plan = validatePlan([
        'select' => [['column' => 'invoice_id']],
        'filters' => [
            'operator' => 'and',
            'conditions' => [
                ['column' => 'status', 'operator' => '=', 'value' => 'paid'],
                [
                    'operator' => 'or',
                    'conditions' => [
                        ['column' => 'total', 'operator' => '>', 'value' => 100],
                        ['column' => 'total', 'operator' => '<', 'value' => 10],
                    ],
                ],
            ],
        ],
    ]);

    expect($plan->filters?->conditions[1]->operator)->toBe(LogicalOperator::Or);
});

it('accepts a filter on a column that cannot be selected', function () {
    $plan = validatePlan([
        'select' => [['column' => 'invoice_id']],
        'filters' => [
            'operator' => 'and',
            'conditions' => [['column' => 'internal_margin', 'operator' => '>', 'value' => 5]],
        ],
    ]);

    expect($plan->filters?->conditions[0]->operator)->toBe(Operator::GreaterThan);
});

it('sorts by a schema column when no alias matches', function () {
    $plan = validatePlan([
        'select' => [['column' => 'invoice_id']],
        'sort' => [['column' => 'issued_at', 'direction' => 'asc']],
    ]);

    expect($plan->sort[0]->isAlias())->toBeFalse()
        ->and($plan->sort[0]->reference)->toBe('issued_at');
});

it('defaults the sort direction to ascending', function () {
    $plan = validatePlan([
        'select' => [['column' => 'invoice_id']],
        'sort' => [['column' => 'issued_at']],
    ]);

    expect($plan->sort[0]->direction)->toBe(SortDirection::Asc);
});
