<?php

declare(strict_types=1);

use AiQueryBuilder\AiQueryBuilder\Exceptions\SchemaDefinitionException;
use AiQueryBuilder\AiQueryBuilder\Exceptions\UnknownResourceException;
use AiQueryBuilder\AiQueryBuilder\Schema\ColumnDefinition;
use AiQueryBuilder\AiQueryBuilder\Schema\Contracts\DefinesQuerySchema;
use AiQueryBuilder\AiQueryBuilder\Schema\ResourceSchema;
use AiQueryBuilder\AiQueryBuilder\Schema\SchemaRegistry;
use Workbench\App\Models\User;

final class UserQuerySchema implements DefinesQuerySchema
{
    public function define(ResourceSchema $schema): ResourceSchema
    {
        return $schema
            ->for(User::class)
            ->name('users')
            ->column('email', fn (ColumnDefinition $column) => $column->filterable(['=']));
    }
}

final class DuplicateUserQuerySchema implements DefinesQuerySchema
{
    public function define(ResourceSchema $schema): ResourceSchema
    {
        return $schema->for(User::class)->name('users');
    }
}

final class UnnamedQuerySchema implements DefinesQuerySchema
{
    public function define(ResourceSchema $schema): ResourceSchema
    {
        return $schema->for(User::class);
    }
}

it('resolves the registry as a singleton', function () {
    expect(app(SchemaRegistry::class))->toBe(app(SchemaRegistry::class));
});

it('starts empty when no resources are configured', function () {
    expect(app(SchemaRegistry::class)->names())->toBe([]);
});

it('resolves resources declared in config', function () {
    config()->set('ai-query-builder.resources', [UserQuerySchema::class]);

    $registry = app(SchemaRegistry::class);

    expect($registry->names())->toBe(['users'])
        ->and($registry->has('users'))->toBeTrue()
        ->and($registry->get('users')->findColumn('email')?->isFilterable())->toBeTrue();
});

it('throws for an unknown resource', function () {
    app(SchemaRegistry::class)->get('missing');
})->throws(UnknownResourceException::class, 'missing');

it('rejects two resources registered under the same name', function () {
    config()->set('ai-query-builder.resources', [UserQuerySchema::class, DuplicateUserQuerySchema::class]);

    app(SchemaRegistry::class)->names();
})->throws(SchemaDefinitionException::class, 'users');

it('rejects a resource that never declared a name', function () {
    config()->set('ai-query-builder.resources', [UnnamedQuerySchema::class]);

    app(SchemaRegistry::class)->names();
})->throws(SchemaDefinitionException::class);

it('accepts resources registered at runtime', function () {
    $registry = app(SchemaRegistry::class);
    $registry->register(UserQuerySchema::class);

    expect($registry->has('users'))->toBeTrue();
});

it('resolves each resource definition only once', function () {
    config()->set('ai-query-builder.resources', [UserQuerySchema::class]);

    $registry = app(SchemaRegistry::class);

    expect($registry->get('users'))->toBe($registry->get('users'));
});
