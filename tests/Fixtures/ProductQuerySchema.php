<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Tests\Fixtures;

use JTMcC\AiQueryBuilder\Schema\ColumnDefinition;
use JTMcC\AiQueryBuilder\Schema\Contracts\DefinesQuerySchema;
use JTMcC\AiQueryBuilder\Schema\ResourceSchema;
use Workbench\App\Models\Product;

/**
 * A second registered resource.
 *
 * Registering two lets a test prove that naming one resource in a plan cannot
 * redirect a tool built for another.
 */
final class ProductQuerySchema implements DefinesQuerySchema
{
    public function define(ResourceSchema $schema): ResourceSchema
    {
        return $schema
            ->for(Product::class)
            ->name('products')
            ->column('id', fn (ColumnDefinition $c) => $c->as('product_id')->sortable())
            ->column('name', fn (ColumnDefinition $c) => $c->as('product_name')->filterable(['=']));
    }
}
