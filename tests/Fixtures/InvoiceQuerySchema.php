<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use JTMcC\AiQueryBuilder\Schema\Contracts\DefinesQuerySchema;
use JTMcC\AiQueryBuilder\Schema\ResourceSchema;

/**
 * The invoice schema as a registered resource, scoped to a single tenant.
 */
final class InvoiceQuerySchema implements DefinesQuerySchema
{
    public function define(ResourceSchema $schema): ResourceSchema
    {
        return InvoiceSchema::make()
            ->alwaysScope(fn (Builder $query) => $query->where('invoices.tenant_id', 1));
    }
}
