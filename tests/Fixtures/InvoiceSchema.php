<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use JTMcC\AiQueryBuilder\Schema\ColumnDefinition;
use JTMcC\AiQueryBuilder\Schema\RelationDefinition;
use JTMcC\AiQueryBuilder\Schema\ResourceSchema;
use Workbench\App\Models\User;

/**
 * A representative schema exercising every capability the validator checks.
 *
 * The model is a stand-in: phase 2 never touches the database, so only the
 * declared structure matters here.
 */
final class InvoiceSchema
{
    public static function make(): ResourceSchema
    {
        return ResourceSchema::make()
            ->for(User::class)
            ->name('invoices')
            ->describe('Customer invoices, one row per invoice.')
            ->column('id', fn (ColumnDefinition $c) => $c->as('invoice_id')->sortable())
            ->column('issued_at', fn (ColumnDefinition $c) => $c
                ->filterable(['=', '>', '<', '>=', '<=', 'between'])
                ->groupableBy(['day', 'month', 'year'])
                ->sortable())
            ->column('total', fn (ColumnDefinition $c) => $c
                ->measuredIn('currency:USD')
                ->aggregatable(['sum', 'avg', 'min', 'max'])
                ->filterable(['>', '<', 'between'])
                ->sortable())
            ->column('status', fn (ColumnDefinition $c) => $c
                ->enum(['draft', 'sent', 'paid', 'void'])
                ->filterable(['=', 'in'])
                ->groupable())
            ->column('internal_margin', fn (ColumnDefinition $c) => $c
                ->filterable(['>'])
                ->selectable(false))
            ->column('customer_notes', fn (ColumnDefinition $c) => $c
                ->visibleWhen(fn (?Authenticatable $user): bool => $user !== null))
            ->relation('lines', fn (RelationDefinition $r) => $r
                ->describe('Line items on the invoice')
                ->column('quantity', fn (ColumnDefinition $c) => $c->aggregatable(['sum']))
                ->relation('product', fn (RelationDefinition $p) => $p
                    ->column('name', fn (ColumnDefinition $c) => $c->filterable(['=', 'like'])->groupable())
                    ->column('type', fn (ColumnDefinition $c) => $c
                        ->enum(['widget', 'service'])
                        ->filterable(['=', 'in'])
                        ->groupable())));
    }
}
