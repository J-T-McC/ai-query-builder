<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use JTMcC\AiQueryBuilder\Tests\Fixtures\InvoiceQuerySchema;
use Workbench\App\Models\Invoice;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai-query-builder.resources', [InvoiceQuerySchema::class]);
});

/**
 * Routes register during boot, so enabling them means re-running the route
 * file. That exercises the file's own config guard rather than bypassing it.
 */
function enableRoutes(): void
{
    config()->set('ai-query-builder.routes.enabled', true);
    config()->set('ai-query-builder.routes.middleware', ['api']);

    require __DIR__.'/../../routes/ai-query-builder.php';

    // Name lookups are built during boot; refresh them for routes added after.
    Route::getRoutes()->refreshNameLookups();
}

/**
 * @param  array<string, mixed>  $plan
 */
function postPlan(string $resource, array $plan): TestResponse
{
    return test()->postJson("/ai-query/{$resource}/query", $plan);
}

it('registers nothing while disabled', function () {
    expect(config('ai-query-builder.routes.enabled'))->toBeFalse()
        ->and(Route::has('ai-query-builder.query'))->toBeFalse();
});

it('ships with the endpoint off and behind auth', function () {
    $shipped = require __DIR__.'/../../config/ai-query-builder.php';

    expect($shipped['routes']['enabled'])->toBeFalse()
        ->and($shipped['routes']['middleware'])->toContain('auth');
});

it('registers the route once enabled', function () {
    enableRoutes();

    expect(Route::has('ai-query-builder.query'))->toBeTrue();
});

it('runs a plan posted as json', function () {
    enableRoutes();

    $invoice = Invoice::create([
        'tenant_id' => 1, 'issued_at' => '2026-02-01', 'total' => 25, 'status' => 'paid',
    ]);

    postPlan('invoices', ['select' => [['column' => 'invoice_id'], ['column' => 'total']]])
        ->assertOk()
        ->assertJsonPath('row_count', 1)
        ->assertJsonPath('rows.0.invoice_id', $invoice->id)
        ->assertJsonPath('columns.total.unit', 'currency:USD');
});

it('applies mandatory scopes the caller cannot influence', function () {
    enableRoutes();

    Invoice::create(['tenant_id' => 1, 'issued_at' => '2026-02-01', 'total' => 1, 'status' => 'paid']);
    Invoice::create(['tenant_id' => 2, 'issued_at' => '2026-02-01', 'total' => 2, 'status' => 'paid']);

    postPlan('invoices', ['select' => [['column' => 'invoice_id']]])
        ->assertOk()
        ->assertJsonPath('row_count', 1);
});

it('returns structured errors with a 422 when the plan is rejected', function () {
    enableRoutes();

    postPlan('invoices', ['select' => [['column' => 'totl']]])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'unknown_column')
        ->assertJsonPath('errors.0.did_you_mean', 'total');
});

it('takes the resource from the url so a route cannot be repointed', function () {
    enableRoutes();

    postPlan('invoices', [
        'resource' => 'something_else',
        'select' => [['column' => 'invoice_id']],
    ])->assertOk();
});

it('does not disclose which resources exist', function () {
    enableRoutes();

    $response = postPlan('ghosts', ['select' => [['column' => 'invoice_id']]]);

    $response->assertStatus(404);

    expect($response->json('message'))->toBe('Unknown resource.')
        ->and($response->getContent())->not->toContain('invoices');
});

it('never returns compiled sql to the client', function () {
    enableRoutes();

    Invoice::create(['tenant_id' => 1, 'issued_at' => '2026-02-01', 'total' => 1, 'status' => 'paid']);

    $response = postPlan('invoices', ['select' => [['column' => 'invoice_id']]]);

    expect($response->getContent())->not->toContain('select "')
        ->not->toContain('tenant_id');
});
