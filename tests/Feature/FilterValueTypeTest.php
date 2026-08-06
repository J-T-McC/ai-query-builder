<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use JTMcC\AiQueryBuilder\Exceptions\InvalidQueryPlanException;
use JTMcC\AiQueryBuilder\Validation\ValidationCode;
use Workbench\App\Models\Invoice;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $condition
 * @return array<string, mixed>
 */
function planFiltering(array $condition): array
{
    return [
        'select' => [['column' => 'invoice_id']],
        'filters' => ['operator' => 'and', 'conditions' => [$condition]],
    ];
}

it('refuses a relative expression as a date filter value', function () {
    // The reported failure. Nothing downstream disagreed with this plan: it
    // validated, compiled, ran, and the comparison behaved as no filter at all,
    // so the agent narrated all-time totals under the heading "last 30 days".
    $errors = rejectPlan(planFiltering([
        'column' => 'issued_at',
        'operator' => '>=',
        'value' => 'now-30d',
    ]));

    expect($errors['filters.conditions.0.value']->code)->toBe(ValidationCode::ValueTypeMismatch)
        ->and($errors['filters.conditions.0.value']->message)
        ->toContain('now-30d')
        ->toContain('not evaluated');
});

it('accepts the absolute date the agent should have sent', function () {
    $plan = validatePlan(planFiltering([
        'column' => 'issued_at',
        'operator' => '>=',
        'value' => '2026-07-07',
    ]));

    expect($plan->filters?->conditions)->toHaveCount(1);
});

it('checks every value in a between range', function () {
    $errors = rejectPlan(planFiltering([
        'column' => 'issued_at',
        'operator' => 'between',
        'value' => ['2026-01-01', 'now'],
    ]));

    expect($errors['filters.conditions.0.value']->code)->toBe(ValidationCode::ValueTypeMismatch);
});

it('refuses text where the column holds a number', function () {
    $errors = rejectPlan(planFiltering([
        'column' => 'total',
        'operator' => '>',
        'value' => 'one hundred',
    ]));

    expect($errors['filters.conditions.0.value']->code)->toBe(ValidationCode::ValueTypeMismatch);
});

it('leaves a column the model says nothing about alone', function () {
    // lines.product.name has no cast, so there is nothing to check against and
    // the plan passes exactly as it did before.
    $plan = validatePlan([
        'select' => [['column' => 'invoice_id']],
        'filters' => [
            'operator' => 'and',
            'conditions' => [['column' => 'lines.product.name', 'operator' => '=', 'value' => 'Widget']],
        ],
    ]);

    expect($plan->filters?->conditions)->toHaveCount(1);
});

it('leaves an enum column to its own stricter check', function () {
    $errors = rejectPlan(planFiltering(['column' => 'status', 'operator' => '=', 'value' => 'pending']));

    expect($errors['filters.conditions.0.value']->code)->toBe(ValidationCode::ValueNotInEnum);
});

it('reports the mismatch as a correctable code an AI layer can retry on', function () {
    try {
        validatePlan(planFiltering(['column' => 'issued_at', 'operator' => '>=', 'value' => 'now-30d']));
    } catch (InvalidQueryPlanException $exception) {
        expect($exception->codes())->toBe(['value_type_mismatch']);

        return;
    }

    $this->fail('The plan should have been rejected.');
});

it('would have answered the wrong question, measured against the database', function () {
    // What the rejection is worth. The same plan with the same operator, run
    // twice against the same rows: the relative string is not a filter at all.
    Invoice::create(['tenant_id' => 1, 'issued_at' => '2020-01-01', 'total' => 1, 'status' => 'paid']);
    Invoice::create(['tenant_id' => 1, 'issued_at' => '2026-08-01', 'total' => 2, 'status' => 'paid']);

    $rows = fn (string $value): int => Invoice::query()
        ->where('issued_at', '>=', $value)
        ->count();

    expect($rows('2026-07-07'))->toBe(1)
        // SQLite compares it as text, so it sorts above every real date and
        // matches nothing. MySQL casts it to a zero date and matches all of
        // them. Two databases, two different wrong answers, no error in either.
        ->and($rows('now-30d'))->not->toBe(1);
});
