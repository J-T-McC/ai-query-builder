<?php

declare(strict_types=1);

use JTMcC\AiQueryBuilder\Exceptions\SchemaDefinitionException;
use JTMcC\AiQueryBuilder\Schema\ColumnDefinition;
use JTMcC\AiQueryBuilder\Schema\RelationDefinition;
use JTMcC\AiQueryBuilder\Schema\ResourceSchema;
use Workbench\App\Models\User;

it('records the model, name and description', function () {
    $schema = ResourceSchema::make()
        ->for(User::class)
        ->name('users')
        ->describe('Application users.');

    expect($schema->model())->toBe(User::class)
        ->and($schema->resourceName())->toBe('users')
        ->and($schema->description())->toBe('Application users.');
});

it('rejects a model that is not an eloquent model', function () {
    ResourceSchema::make()->for(stdClass::class);
})->throws(SchemaDefinitionException::class, 'stdClass');

it('rejects a resource name a provider would refuse as a tool name', function (string $name) {
    ResourceSchema::make()->for(User::class)->name($name);
})->with([
    'a space' => 'sales invoices',
    'a dot' => 'sales.invoices',
    'empty' => '',
])->throws(SchemaDefinitionException::class);

it('accepts a resource name a provider would accept', function (string $name) {
    expect(ResourceSchema::make()->for(User::class)->name($name)->resourceName())->toBe($name);
})->with(['invoices', 'delivery_attempts', 'proxy-logs', 'v2']);

it('declares columns via a closure', function () {
    $schema = ResourceSchema::make()
        ->for(User::class)
        ->name('users')
        ->column('id')
        ->column('email', fn (ColumnDefinition $column) => $column->filterable(['=']));

    expect($schema->columns())->toHaveCount(2)
        ->and($schema->findColumn('email')?->isFilterable())->toBeTrue()
        ->and($schema->findColumn('id')?->isFilterable())->toBeFalse();
});

it('indexes columns by their exposed alias', function () {
    $schema = ResourceSchema::make()
        ->for(User::class)
        ->name('users')
        ->column('id', fn (ColumnDefinition $column) => $column->as('user_id'));

    expect($schema->findColumn('user_id'))->toBeInstanceOf(ColumnDefinition::class)
        ->and($schema->findColumn('id'))->toBeNull();
});

it('rejects duplicate column names', function () {
    ResourceSchema::make()
        ->for(User::class)
        ->name('users')
        ->column('email')
        ->column('email');
})->throws(SchemaDefinitionException::class, 'email');

it('rejects duplicate relation names', function () {
    ResourceSchema::make()
        ->for(User::class)
        ->name('users')
        ->relation('lines')
        ->relation('lines');
})->throws(SchemaDefinitionException::class, 'lines');

it('declares nested relations', function () {
    $schema = ResourceSchema::make()
        ->for(User::class)
        ->name('invoices')
        ->relation('lines', fn (RelationDefinition $relation) => $relation
            ->describe('Line items')
            ->column('quantity')
            ->relation('product', fn (RelationDefinition $product) => $product
                ->column('type', fn (ColumnDefinition $column) => $column->filterable(['='])->aggregatable(['count'])),
            ),
        );

    expect($schema->findRelation('lines')?->description())->toBe('Line items')
        ->and($schema->findRelation('lines.product'))->toBeInstanceOf(RelationDefinition::class)
        ->and($schema->findRelation('lines.missing'))->toBeNull();
});

it('resolves columns through relation paths', function () {
    $schema = ResourceSchema::make()
        ->for(User::class)
        ->name('invoices')
        ->column('total')
        ->relation('lines', fn (RelationDefinition $relation) => $relation
            ->relation('product', fn (RelationDefinition $product) => $product->column('type')),
        );

    expect($schema->findColumn('total'))->toBeInstanceOf(ColumnDefinition::class)
        ->and($schema->findColumn('lines.product.type'))->toBeInstanceOf(ColumnDefinition::class)
        ->and($schema->findColumn('lines.product.missing'))->toBeNull()
        ->and($schema->findColumn('unknown.type'))->toBeNull();
});

it('reports the relation depth of a path', function () {
    $schema = ResourceSchema::make()->for(User::class)->name('invoices');

    expect($schema->depthOf('total'))->toBe(0)
        ->and($schema->depthOf('lines.product.type'))->toBe(2);
});

it('applies limit defaults', function () {
    $limits = ResourceSchema::make()->for(User::class)->name('users')->limits();

    expect($limits->default)->toBe(100)
        ->and($limits->max)->toBe(1000)
        ->and($limits->maxRelationDepth)->toBe(2);
});

it('allows limits to be overridden', function () {
    $limits = ResourceSchema::make()
        ->for(User::class)
        ->name('users')
        ->maxLimit(50)
        ->defaultLimit(25)
        ->maxRelationDepth(1)
        ->limits();

    expect($limits->default)->toBe(25)
        ->and($limits->max)->toBe(50)
        ->and($limits->maxRelationDepth)->toBe(1);
});

it('rejects a default limit above the max limit', function () {
    ResourceSchema::make()->for(User::class)->name('users')->maxLimit(10)->defaultLimit(50);
})->throws(SchemaDefinitionException::class);

it('clamps the default limit when the max is lowered beneath it', function () {
    $limits = ResourceSchema::make()->for(User::class)->name('users')->maxLimit(10)->limits();

    expect($limits->default)->toBe(10);
});

it('collects always scopes in declaration order', function () {
    $schema = ResourceSchema::make()
        ->for(User::class)
        ->name('users')
        ->alwaysScope(fn () => 'first')
        ->alwaysScope(fn () => 'second');

    expect($schema->alwaysScopes())->toHaveCount(2);
});

it('filters columns by visibility for the given user', function () {
    $schema = ResourceSchema::make()
        ->for(User::class)
        ->name('users')
        ->column('email')
        ->column('notes', fn (ColumnDefinition $column) => $column->visibleWhen(fn (?object $user): bool => $user !== null));

    expect(array_keys($schema->visibleColumns(null)))->toBe(['email'])
        ->and(array_keys($schema->visibleColumns(new User)))->toBe(['email', 'notes']);
});
