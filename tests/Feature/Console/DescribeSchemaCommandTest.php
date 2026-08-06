<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use JTMcC\AiQueryBuilder\Tests\Fixtures\InvoiceQuerySchema;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai-query-builder.resources', [InvoiceQuerySchema::class]);
    config()->set('auth.providers.users.model', User::class);
});

/**
 * @param  array<string, mixed>  $arguments
 * @return array{0: int, 1: string}
 */
function describeSchema(array $arguments = []): array
{
    $status = Artisan::call('ai-query:describe', $arguments);

    return [$status, Artisan::output()];
}

it('lists registered resources when none is named', function () {
    [$status, $output] = describeSchema();

    expect($status)->toBe(0)->and($output)->toContain('invoices');
});

it('warns when nothing is registered', function () {
    config()->set('ai-query-builder.resources', []);

    [$status, $output] = describeSchema();

    expect($status)->toBe(0)->and($output)->toContain('No resources are registered');
});

it('prints the prompt an agent receives', function () {
    [$status, $output] = describeSchema(['resource' => 'invoices']);

    expect($status)->toBe(0)
        ->and($output)->toContain('Resource: invoices')
        ->toContain('lines.product.type')
        ->toContain('Returns 100 rows unless a limit is given, up to 1000');
});

it('prints the json schema with --json', function () {
    [$status, $output] = describeSchema(['resource' => 'invoices', '--json' => true]);

    expect($status)->toBe(0)
        ->and(json_decode($output, true))
        ->toHaveKey('$defs');
});

it('reports what the contract costs on the wire with --cost', function () {
    [$status, $output] = describeSchema(['resource' => 'invoices', '--cost' => true]);

    expect($status)->toBe(0)
        ->and($output)->toContain('description')
        ->toContain('input_schema')
        ->toContain('filters')
        ->toContain('depth 3')
        ->toContain('current');
});

it('accounts for every byte of the schema it reports', function () {
    [, $output] = describeSchema(['resource' => 'invoices', '--cost' => true]);

    // The per-property rows plus the envelope must sum to the reported total,
    // or the breakdown is quietly hiding bytes that are still being billed.
    preg_match('/input_schema\s+\.+\s+([\d,]+)/', $output, $total);
    preg_match_all('/\n\s+(?:\w+|envelope)\s+\.+\s+([\d,]+) \d+%/', $output, $parts);

    $sum = array_sum(array_map(fn (string $n): int => (int) str_replace(',', '', $n), $parts[1]));

    expect($sum)->toBe((int) str_replace(',', '', $total[1]));
});

it('costs a gated column only for the user who can see it', function () {
    $user = User::factory()->create();

    [, $anonymous] = describeSchema(['resource' => 'invoices', '--cost' => true]);
    [, $known] = describeSchema(['resource' => 'invoices', '--user' => $user->id, '--cost' => true]);

    expect($known)->not->toBe($anonymous);
});

it('omits a gated column when no user is given', function () {
    [, $output] = describeSchema(['resource' => 'invoices']);

    expect($output)->not->toContain('customer_notes');
});

it('includes a gated column for a user who can see it', function () {
    $user = User::factory()->create();

    [, $output] = describeSchema(['resource' => 'invoices', '--user' => $user->id]);

    expect($output)->toContain('customer_notes');
});

it('fails for an unknown resource', function () {
    [$status] = describeSchema(['resource' => 'ghosts']);

    expect($status)->toBe(1);
});

it('fails for a user that does not exist', function () {
    [$status] = describeSchema(['resource' => 'invoices', '--user' => 999]);

    expect($status)->toBe(1);
});
