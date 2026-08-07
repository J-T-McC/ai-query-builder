<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use JTMcC\AiQueryBuilder\Schema\ColumnDefinition;
use JTMcC\AiQueryBuilder\Schema\RelationDefinition;
use JTMcC\AiQueryBuilder\Schema\ResourceSchema;
use Workbench\App\Models\Invoice;

/**
 * A representative schema exercising every capability the validator and the
 * compiler check, backed by the workbench invoice tables.
 */
final class InvoiceSchema
{
    public static function make(): ResourceSchema
    {
        return ResourceSchema::make()
            ->for(Invoice::class)
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
            ->relation('tags', fn (RelationDefinition $t) => $t
                ->describe('Tags applied to the invoice')
                ->column('name', fn (ColumnDefinition $c) => $c
                    ->filterable(['=', 'in'])
                    ->groupable()
                    ->sortable()))
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
