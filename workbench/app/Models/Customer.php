<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Customer extends Model
{
    protected $guarded = [];

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Line items reached through the customer's invoices.
     *
     * The intermediate model soft deletes, which is what makes this worth
     * having: a line hanging off a deleted invoice must not be reachable.
     *
     * @return HasManyThrough<InvoiceLine, Invoice, $this>
     */
    public function lines(): HasManyThrough
    {
        return $this->hasManyThrough(InvoiceLine::class, Invoice::class);
    }

    /**
     * @return HasOneThrough<InvoiceLine, Invoice, $this>
     */
    public function firstLine(): HasOneThrough
    {
        return $this->hasOneThrough(InvoiceLine::class, Invoice::class);
    }
}
