<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use JTMcC\AiQueryBuilder\Events\QueryPlanExecuted;
use JTMcC\AiQueryBuilder\Events\QueryPlanRejected;
use JTMcC\AiQueryBuilder\Events\QueryPlanValidated;
use JTMcC\AiQueryBuilder\Exceptions\ExecutionException;
use JTMcC\AiQueryBuilder\Exceptions\InvalidQueryPlanException;
use JTMcC\AiQueryBuilder\Exceptions\UnknownResourceException;
use JTMcC\AiQueryBuilder\Execution\QueryRunner;
use JTMcC\AiQueryBuilder\Tests\Fixtures\InvoiceQuerySchema;
use Workbench\App\Models\Invoice;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai-query-builder.resources', [InvoiceQuerySchema::class]);
});

function runner(): QueryRunner
{
    return app(QueryRunner::class);
}

function invoice(int $tenant = 1, string $total = '10.00'): Invoice
{
    return Invoice::create([
        'tenant_id' => $tenant,
        'issued_at' => '2026-02-01',
        'total' => $total,
        'status' => 'paid',
    ]);
}

$plan = ['resource' => 'invoices', 'select' => [['column' => 'invoice_id'], ['column' => 'total']]];

describe('running', function () use ($plan) {
    it('returns rows for a valid plan', function () use ($plan) {
        $one = invoice();
        invoice(tenant: 2);

        $result = runner()->run($plan);

        expect($result->count())->toBe(1)
            ->and($result->rows[0]['invoice_id'])->toBe($one->id)
            ->and($result->resource)->toBe('invoices')
            ->and($result->truncated)->toBeFalse();
    });

    it('carries unit metadata so a model narrates the number correctly', function () use ($plan) {
        invoice();

        expect(runner()->run($plan)->columns['total'])->toBe(['unit' => 'currency:USD']);
    });

    it('serialises to json without leaking timing data', function () use ($plan) {
        invoice();

        $json = json_decode(runner()->run($plan)->toJson(), true);

        expect($json)->toHaveKeys(['resource', 'columns', 'rows', 'row_count', 'truncated'])
            ->and($json)->not->toHaveKey('durationMs');
    });

    it('resolves gated columns for the acting user', function () {
        invoice();

        $plan = ['resource' => 'invoices', 'select' => [['column' => 'customer_notes']]];

        expect(fn () => runner()->run($plan))->toThrow(InvalidQueryPlanException::class)
            ->and(runner()->as(new User)->run($plan)->count())->toBe(1);
    });
});

describe('guardrails', function () use ($plan) {
    it('flags a truncated result rather than passing it off as complete', function () use ($plan) {
        invoice();
        invoice();

        $result = runner()->maxRows(1)->run($plan);

        expect($result->count())->toBe(1)
            ->and($result->truncated)->toBeTrue();
    });

    it('does not flag truncation when everything fits', function () use ($plan) {
        invoice();

        expect(runner()->maxRows(1)->run($plan)->truncated)->toBeFalse();
    });

    it('refuses a timeout on a driver that cannot enforce one', function () use ($plan) {
        invoice();

        runner()->timeout(1000)->run($plan);
    })->throws(ExecutionException::class, 'cannot be enforced');

    it('runs without a timeout by default', function () use ($plan) {
        invoice();

        expect(runner()->run($plan)->count())->toBe(1);
    });

    it('does not let one configured runner leak settings into the next', function () use ($plan) {
        invoice();
        invoice();

        $base = runner();
        $capped = $base->maxRows(1);

        expect($capped->run($plan)->truncated)->toBeTrue()
            ->and($base->run($plan)->truncated)->toBeFalse();
    });
});

describe('explain', function () use ($plan) {
    it('returns sql and bindings without running the query', function () use ($plan) {
        Event::fake([QueryPlanExecuted::class]);

        $compiled = runner()->explain($plan);

        expect($compiled->sql)->toContain('select')
            ->and($compiled->bindings)->toBe([1])
            ->and($compiled->toArray()['plan']['resource'])->toBe('invoices');

        Event::assertNotDispatched(QueryPlanExecuted::class);
    });
});

describe('events', function () use ($plan) {
    it('dispatches the audit event with the sql that ran', function () use ($plan) {
        Event::fake([QueryPlanExecuted::class]);

        invoice();
        runner()->withPrompt('how many invoices?')->run($plan);

        Event::assertDispatched(QueryPlanExecuted::class, function (QueryPlanExecuted $event): bool {
            return $event->rowCount === 1
                && $event->prompt === 'how many invoices?'
                && str_contains($event->sql, '"invoices"."tenant_id" = ?')
                && $event->bindings === [1];
        });
    });

    it('dispatches a validated event before compiling', function () use ($plan) {
        Event::fake([QueryPlanValidated::class]);

        runner()->explain($plan);

        Event::assertDispatched(QueryPlanValidated::class);
    });

    it('reports rejections with their error codes for failure-rate metrics', function () {
        Event::fake([QueryPlanRejected::class]);

        try {
            runner()->run(['resource' => 'invoices', 'select' => [['column' => 'nope']]]);
        } catch (InvalidQueryPlanException) {
            // The exception still surfaces; the event is for measurement.
        }

        Event::assertDispatched(QueryPlanRejected::class, function (QueryPlanRejected $event): bool {
            return $event->resource === 'invoices'
                && $event->errorCodes() === ['unknown_column'];
        });
    });

    it('still throws after reporting a rejection', function () {
        runner()->run(['resource' => 'invoices', 'select' => [['column' => 'nope']]]);
    })->throws(InvalidQueryPlanException::class);
});

describe('resource resolution', function () {
    it('requires the plan to name a resource', function () {
        runner()->run(['select' => [['column' => 'invoice_id']]]);
    })->throws(ExecutionException::class, 'must name the resource');

    it('rejects a resource that is not registered', function () {
        runner()->run(['resource' => 'ghosts', 'select' => [['column' => 'invoice_id']]]);
    })->throws(UnknownResourceException::class);
});
