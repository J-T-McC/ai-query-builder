<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Product extends Model
{
    protected $guarded = [];

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /**
     * A second parent type on the notes table, so a morph type condition that
     * goes missing shows up as another model's rows rather than as nothing.
     *
     * @return MorphMany<Note, $this>
     */
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable');
    }

    /**
     * @return MorphToMany<Tag, $this>
     */
    public function morphTags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }
}
