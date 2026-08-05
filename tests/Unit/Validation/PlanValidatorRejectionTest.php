<?php

declare(strict_types=1);

use JTMcC\AiQueryBuilder\Exceptions\InvalidQueryPlanException;
use JTMcC\AiQueryBuilder\Tests\Fixtures\InvoiceSchema;
use JTMcC\AiQueryBuilder\Validation\PlanValidator;
use JTMcC\AiQueryBuilder\Validation\ValidationCode;
use Workbench\App\Models\User;

$select = [['column' => 'invoice_id']];

it('rejects unknown top-level keys rather than dropping them', function () use ($select) {
    $errors = rejectPlan(['select' => $select, 'raw' => 'DROP TABLE invoices']);

    expect($errors['raw']->code)->toBe(ValidationCode::UnknownKey);
});

it('rejects unknown keys inside a select entry', function () {
    $errors = rejectPlan(['select' => [['column' => 'total', 'expression' => 'now()']]]);

    expect($errors['select.0.expression']->code)->toBe(ValidationCode::UnknownKey);
});

it('rejects an empty select', function () {
    expect(rejectPlan(['select' => []])['select']->code)->toBe(ValidationCode::EmptySelect);
});

it('rejects a plan targeting a different resource', function () use ($select) {
    $errors = rejectPlan(['resource' => 'users', 'select' => $select]);

    expect($errors['resource']->code)->toBe(ValidationCode::UnknownResource);
});

it('rejects an unknown column and suggests the closest match', function () {
    $errors = rejectPlan(['select' => [['column' => 'totl']]]);

    expect($errors['select.0.column']->code)->toBe(ValidationCode::UnknownColumn)
        ->and($errors['select.0.column']->didYouMean)->toBe('total');
});

it('withholds a suggestion when nothing is close', function () {
    expect(rejectPlan(['select' => [['column' => 'x']]])['select.0.column']->didYouMean)->toBeNull();
});

it('rejects a column that is filterable but not selectable', function () {
    $errors = rejectPlan(['select' => [['column' => 'internal_margin']]]);

    expect($errors['select.0.column']->code)->toBe(ValidationCode::ColumnNotSelectable);
});

it('rejects filtering a column with no permitted operators', function () use ($select) {
    $errors = rejectPlan([
        'select' => $select,
        'filters' => [
            'operator' => 'and',
            'conditions' => [['column' => 'invoice_id', 'operator' => '=', 'value' => 1]],
        ],
    ]);

    expect($errors['filters.conditions.0.column']->code)->toBe(ValidationCode::ColumnNotFilterable);
});

it('rejects an operator the column does not permit', function () use ($select) {
    $errors = rejectPlan([
        'select' => $select,
        'filters' => [
            'operator' => 'and',
            'conditions' => [['column' => 'total', 'operator' => 'like', 'value' => '%1%']],
        ],
    ]);

    expect($errors['filters.conditions.0.operator']->code)->toBe(ValidationCode::OperatorNotAllowed);
});

it('rejects an aggregate the column does not permit', function () {
    $errors = rejectPlan(['select' => [['column' => 'total', 'function' => 'count_distinct']]]);

    expect($errors['select.0.function']->code)->toBe(ValidationCode::FunctionNotAllowed);
});

it('rejects grouping by a column that is not groupable', function () {
    $errors = rejectPlan([
        'select' => [['column' => 'total', 'function' => 'sum']],
        'group_by' => [['column' => 'invoice_id']],
    ]);

    expect($errors['group_by.0.column']->code)->toBe(ValidationCode::ColumnNotGroupable);
});

it('rejects a date bucket the column does not permit', function () {
    $errors = rejectPlan([
        'select' => [['column' => 'issued_at'], ['column' => 'total', 'function' => 'sum']],
        'group_by' => [['column' => 'issued_at', 'bucket' => 'week']],
    ]);

    expect($errors['group_by.0.bucket']->code)->toBe(ValidationCode::BucketNotAllowed);
});

it('rejects a value outside the column enum', function () use ($select) {
    $errors = rejectPlan([
        'select' => $select,
        'filters' => [
            'operator' => 'and',
            'conditions' => [['column' => 'status', 'operator' => '=', 'value' => 'pending']],
        ],
    ]);

    expect($errors['filters.conditions.0.value']->code)->toBe(ValidationCode::ValueNotInEnum);
});

it('rejects sorting by a column that is not sortable', function () use ($select) {
    $errors = rejectPlan(['select' => $select, 'sort' => [['column' => 'status']]]);

    expect($errors['sort.0.column']->code)->toBe(ValidationCode::ColumnNotSortable);
});

it('rejects having against something that is not an aggregated alias', function () {
    $errors = rejectPlan([
        'select' => [['column' => 'total', 'function' => 'sum', 'as' => 'total_sum']],
        'having' => [['column' => 'invoice_id', 'operator' => '>', 'value' => 1]],
    ]);

    expect($errors['having.0.column']->code)->toBe(ValidationCode::UnknownAlias);
});

it('rejects a having operator that is not meaningful after aggregation', function () {
    $errors = rejectPlan([
        'select' => [['column' => 'total', 'function' => 'sum', 'as' => 'total_sum']],
        'having' => [['column' => 'total_sum', 'operator' => 'like', 'value' => '5%']],
    ]);

    expect($errors['having.0.operator']->code)->toBe(ValidationCode::OperatorNotAllowed);
});

it('rejects duplicate select aliases', function () {
    $errors = rejectPlan([
        'select' => [
            ['column' => 'total', 'function' => 'sum', 'as' => 'figure'],
            ['column' => 'total', 'function' => 'avg', 'as' => 'figure'],
        ],
    ]);

    expect($errors['select.1.as']->code)->toBe(ValidationCode::DuplicateAlias);
});

it('rejects an alias that is not a safe identifier', function () {
    $errors = rejectPlan([
        'select' => [['column' => 'total', 'function' => 'sum', 'as' => 'total") from users --']],
    ]);

    expect($errors['select.0.as']->code)->toBe(ValidationCode::InvalidValueShape);
});

it('rejects a non-aggregated column selected alongside an aggregate but not grouped', function () {
    $errors = rejectPlan([
        'select' => [
            ['column' => 'status'],
            ['column' => 'total', 'function' => 'sum'],
        ],
    ]);

    expect($errors['select.0.column']->code)->toBe(ValidationCode::UngroupedColumn);
});

it('rejects a limit above the schema maximum instead of silently clamping it', function () use ($select) {
    $errors = rejectPlan(['select' => $select, 'limit' => 50_000]);

    expect($errors['limit']->code)->toBe(ValidationCode::LimitOutOfRange);
});

it('rejects a path traversing more relations than the schema permits', function () {
    $schema = InvoiceSchema::make()->maxRelationDepth(1);

    try {
        (new PlanValidator)->validate(['select' => [['column' => 'lines.product.name']]], $schema);
    } catch (InvalidQueryPlanException $exception) {
        expect($exception->errors()[0]->code)->toBe(ValidationCode::RelationDepthExceeded);

        return;
    }

    $this->fail('The plan should have been rejected.');
});

describe('value shapes', function () use ($select) {
    $filter = fn (array $condition): array => [
        'select' => $select,
        'filters' => ['operator' => 'and', 'conditions' => [$condition]],
    ];

    it('rejects between with the wrong number of values', function () use ($filter) {
        $errors = rejectPlan($filter(['column' => 'total', 'operator' => 'between', 'value' => [1]]));

        expect($errors['filters.conditions.0.value']->code)->toBe(ValidationCode::InvalidValueShape);
    });

    it('rejects an empty list for in', function () use ($filter) {
        $errors = rejectPlan($filter(['column' => 'status', 'operator' => 'in', 'value' => []]));

        expect($errors['filters.conditions.0.value']->code)->toBe(ValidationCode::InvalidValueShape);
    });

    it('rejects a scalar where a list is required', function () use ($filter) {
        $errors = rejectPlan($filter(['column' => 'status', 'operator' => 'in', 'value' => 'paid']));

        expect($errors['filters.conditions.0.value']->code)->toBe(ValidationCode::InvalidValueShape);
    });

    it('rejects a nested array as a scalar value', function () use ($filter) {
        $errors = rejectPlan($filter(['column' => 'total', 'operator' => '>', 'value' => ['nested' => 1]]));

        expect($errors['filters.conditions.0.value']->code)->toBe(ValidationCode::InvalidValueShape);
    });
});

describe('filter complexity', function () use ($select) {
    it('rejects filters nested deeper than the schema permits', function () use ($select) {
        $group = ['operator' => 'and', 'conditions' => [['column' => 'total', 'operator' => '>', 'value' => 1]]];

        for ($i = 0; $i < 6; $i++) {
            $group = ['operator' => 'and', 'conditions' => [$group]];
        }

        expect(rejectPlan(['select' => $select, 'filters' => $group]))
            ->toHaveKey('filters.conditions.0.conditions.0.conditions.0.conditions.0.conditions.0');
    });

    it('rejects a filter tree with too many conditions', function () use ($select) {
        $conditions = array_fill(0, 60, ['column' => 'total', 'operator' => '>', 'value' => 1]);

        $errors = rejectPlan([
            'select' => $select,
            'filters' => ['operator' => 'or', 'conditions' => $conditions],
        ]);

        expect($errors['filters']->code)->toBe(ValidationCode::FilterTooComplex);
    });
});

describe('per-user visibility', function () {
    it('resolves a gated column for a user who may see it', function () {
        $plan = validatePlan(['select' => [['column' => 'customer_notes']]], new User);

        expect($plan->select[0]->path)->toBe('customer_notes');
    });

    it('reports a gated column as unknown rather than forbidden', function () {
        $errors = rejectPlan(['select' => [['column' => 'customer_notes']]]);

        expect($errors['select.0.column']->code)->toBe(ValidationCode::UnknownColumn);
    });

    it('never suggests a column the user cannot see', function () {
        $errors = rejectPlan(['select' => [['column' => 'customer_note']]]);

        expect($errors['select.0.column']->didYouMean)->not->toBe('customer_notes');
    });
});

describe('error reporting', function () use ($select) {
    it('accumulates every error in one pass', function () {
        $errors = rejectPlan([
            'select' => [['column' => 'nope'], ['column' => 'internal_margin']],
            'limit' => 0,
        ]);

        expect($errors)->toHaveKeys(['select.0.column', 'select.1.column', 'limit']);
    });

    it('exposes deduplicated codes for failure-rate metrics', function () use ($select) {
        try {
            validatePlan(['select' => $select, 'limit' => 0, 'bogus' => 1, 'other' => 2]);
        } catch (InvalidQueryPlanException $exception) {
            expect($exception->codes())->toBe(['unknown_key', 'limit_out_of_range']);

            return;
        }

        $this->fail('The plan should have been rejected.');
    });

    it('serialises errors for an AI layer to act on', function () {
        try {
            validatePlan(['select' => [['column' => 'totl']]]);
        } catch (InvalidQueryPlanException $exception) {
            expect($exception->toArray()[0])->toBe([
                'path' => 'select.0.column',
                'code' => 'unknown_column',
                'message' => 'Unknown column [totl].',
                'did_you_mean' => 'total',
            ]);

            return;
        }

        $this->fail('The plan should have been rejected.');
    });
});
