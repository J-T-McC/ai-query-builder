<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Invoice;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->target = sys_get_temp_dir().'/aiqb-'.bin2hex(random_bytes(4));
});

afterEach(function () {
    if (is_dir($this->target)) {
        array_map('unlink', glob($this->target.DIRECTORY_SEPARATOR.'*.php') ?: []);
        rmdir($this->target);
    }
});

function generate(string $path, string $model, array $options = []): string
{
    $status = Artisan::call('ai-query:make-schema', ['model' => $model, '--path' => $path] + $options);

    expect($status)->toBe(0, Artisan::output());

    return (string) file_get_contents($path.DIRECTORY_SEPARATOR.class_basename($model).'QuerySchema.php');
}

it('writes a class bound to the model and table', function () {
    $contents = generate($this->target, Invoice::class);

    expect($contents)->toContain('final class InvoiceQuerySchema implements DefinesQuerySchema')
        ->toContain('->for(Invoice::class)')
        ->toContain("->name('invoices')");
});

it('comments out every column so exposure is a deliberate edit', function () {
    $contents = generate($this->target, Invoice::class);

    expect($contents)->toContain("// ->column('total'")
        ->toContain("// ->column('status'")
        ->and($contents)->not->toContain("\n            ->column(");
});

it('suggests aggregates only for numeric columns', function () {
    $contents = generate($this->target, Invoice::class);

    $total = substr($contents, (int) strpos($contents, "->column('total'"), 200);
    $status = substr($contents, (int) strpos($contents, "->column('status'"), 200);

    expect($total)->toContain("aggregatable(['sum', 'avg', 'min', 'max'])")
        ->and($status)->not->toContain('aggregatable');
});

it('suggests date bucketing only for date columns', function () {
    $contents = generate($this->target, Invoice::class);

    $issued = substr($contents, (int) strpos($contents, "->column('issued_at'"), 250);

    expect($issued)->toContain("groupableBy(['day', 'week', 'month', 'quarter', 'year'])");
});

it('leaves sensitive columns out entirely rather than commenting them', function () {
    $contents = generate($this->target, User::class);

    expect($contents)->not->toContain("->column('password'")
        ->not->toContain("->column('remember_token'")
        ->toContain('Not scaffolded, names suggest secrets')
        ->toContain('password')
        ->toContain('remember_token');
});

it('scaffolds declared relations one level deep', function () {
    $contents = generate($this->target, Invoice::class);

    expect($contents)->toContain("// ->relation('lines', fn (RelationDefinition \$r) => \$r")
        ->toContain("//     ->column('quantity')");
});

it('leaves a mandatory scope stub that must be filled in', function () {
    expect(generate($this->target, Invoice::class))
        ->toContain('// ->alwaysScope(')
        ->toContain('no plan can remove them');
});

it('produces a file that parses', function () {
    $path = $this->target.DIRECTORY_SEPARATOR.'InvoiceQuerySchema.php';
    generate($this->target, Invoice::class);

    // PHP_BINARY rather than "php": the CI runner's PATH is not guaranteed,
    // and this test also runs on Windows.
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($path), $output, $status);

    expect($status)->toBe(0);
});

it('refuses to overwrite without --force', function () {
    generate($this->target, Invoice::class);

    $this->artisan('ai-query:make-schema', ['model' => Invoice::class, '--path' => $this->target])
        ->assertFailed();
});

it('overwrites with --force', function () {
    generate($this->target, Invoice::class);

    $this->artisan('ai-query:make-schema', [
        'model' => Invoice::class,
        '--path' => $this->target,
        '--force' => true,
    ])->assertSuccessful();
});

it('fails when the model cannot be resolved', function () {
    $this->artisan('ai-query:make-schema', ['model' => 'Nope\\Missing', '--path' => $this->target])
        ->assertFailed();
});
