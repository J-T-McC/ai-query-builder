<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Schema;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use JTMcC\AiQueryBuilder\Schema\Enums\ColumnType;
use Throwable;

/**
 * Works out what kind of value a declared column holds.
 *
 * A declaration on the column wins. Failing that the type is read from the
 * Eloquent model the path resolves to, because the application has already
 * stated it there and restating it in the schema would be a second place to get
 * it wrong.
 *
 * Inference fails open: an unrecognised cast, a relation this cannot follow, or
 * a model that will not instantiate all yield no type, and no type means no
 * check. That polarity is deliberate — a guess that rejects a valid plan costs
 * a correction round trip on a query that was already right, which is a worse
 * trade than the one this class exists to make.
 */
final class ColumnTypeResolver
{
    /** @var array<string, ColumnType|null> */
    private array $inferred = [];

    /**
     * @param  class-string<Model>|null  $model
     */
    public function __construct(private readonly ?string $model) {}

    public function resolve(string $path, ColumnDefinition $column): ?ColumnType
    {
        if ($column->type() !== null) {
            return $column->type();
        }

        if ($this->model === null) {
            return null;
        }

        // array_key_exists rather than ??=, because a path that infers to null
        // is a cached answer and not a cache miss.
        if (! array_key_exists($path, $this->inferred)) {
            $this->inferred[$path] = $this->infer($path, $column);
        }

        return $this->inferred[$path];
    }

    private function infer(string $path, ColumnDefinition $column): ?ColumnType
    {
        $segments = explode('.', $path);
        array_pop($segments);

        $model = $this->modelFor($segments);

        return $model === null ? null : $this->fromCasts($model, $column->name());
    }

    /**
     * Follow the relation segments of a path to the model that owns the column.
     *
     * @param  list<string>  $segments
     */
    private function modelFor(array $segments): ?Model
    {
        try {
            $model = new ($this->model);

            foreach ($segments as $segment) {
                if (! method_exists($model, $segment)) {
                    return null;
                }

                $relation = $model->{$segment}();

                if (! $relation instanceof Relation) {
                    return null;
                }

                $model = $relation->getRelated();
            }

            return $model;
        } catch (Throwable) {
            // A relation method that needs more than a bare model is not a
            // failure worth raising here. The compiler resolves the same
            // relation later and reports it properly if it is genuinely broken.
            return null;
        }
    }

    /**
     * Read the type off the model, preferring what it says over what it stores.
     *
     * Timestamps are not in getCasts(), so getDates() has to be consulted
     * separately. A cast this does not recognise — a collection, an enum class,
     * an encrypted or hashed value — yields no type rather than a guess.
     */
    private function fromCasts(Model $model, string $attribute): ?ColumnType
    {
        if (in_array($attribute, $model->getDates(), strict: true)) {
            return ColumnType::Datetime;
        }

        return match (true) {
            $model->hasCast($attribute, ['date', 'immutable_date']) => ColumnType::Date,
            $model->hasCast($attribute, [
                'datetime',
                'immutable_datetime',
                'custom_datetime',
                'immutable_custom_datetime',
            ]) => ColumnType::Datetime,
            $model->hasCast($attribute, ['int', 'integer']) => ColumnType::Integer,
            $model->hasCast($attribute, ['float', 'double', 'real', 'decimal']) => ColumnType::Number,
            $model->hasCast($attribute, ['bool', 'boolean']) => ColumnType::Boolean,
            $model->hasCast($attribute, ['string']) => ColumnType::String,
            default => null,
        };
    }
}
