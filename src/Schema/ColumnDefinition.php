<?php

declare(strict_types=1);

namespace AiQueryBuilder\AiQueryBuilder\Schema;

use AiQueryBuilder\AiQueryBuilder\Exceptions\SchemaDefinitionException;
use AiQueryBuilder\AiQueryBuilder\Schema\Enums\Aggregate;
use AiQueryBuilder\AiQueryBuilder\Schema\Enums\DateBucket;
use AiQueryBuilder\AiQueryBuilder\Schema\Enums\Operator;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A single column exposed to an AI agent.
 *
 * Every capability beyond selection is opt-in: a declared column can be read but
 * cannot be filtered, sorted, grouped or aggregated until explicitly permitted.
 */
final class ColumnDefinition
{
    private ?string $alias = null;

    private ?string $description = null;

    private bool $selectable = true;

    private bool $sortable = false;

    private bool $groupable = false;

    /** @var list<Operator> */
    private array $operators = [];

    /** @var list<Aggregate> */
    private array $aggregates = [];

    /** @var list<DateBucket> */
    private array $dateBuckets = [];

    /** @var list<string>|null */
    private ?array $enum = null;

    private ?string $unit = null;

    /** @var Closure(Authenticatable|null): bool|null */
    private ?Closure $visibility = null;

    public function __construct(private readonly string $name) {}

    /**
     * Expose the column under a different name than the underlying attribute.
     */
    public function as(string $alias): self
    {
        $this->alias = $alias;

        return $this;
    }

    public function describe(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function selectable(bool $selectable = true): self
    {
        $this->selectable = $selectable;

        return $this;
    }

    public function sortable(bool $sortable = true): self
    {
        $this->sortable = $sortable;

        return $this;
    }

    public function groupable(bool $groupable = true): self
    {
        $this->groupable = $groupable;

        return $this;
    }

    /**
     * Permit filtering with the given operators, and only those operators.
     *
     * @param  list<Operator|string>  $operators
     */
    public function filterable(array $operators): self
    {
        $this->operators = $this->normalize($operators, Operator::class, 'operator');

        return $this;
    }

    /**
     * Permit the given aggregate functions on this column, and only those.
     *
     * @param  list<Aggregate|string>  $aggregates
     */
    public function aggregatable(array $aggregates): self
    {
        $this->aggregates = $this->normalize($aggregates, Aggregate::class, 'aggregate');

        return $this;
    }

    /**
     * Permit grouping by the given date granularities. Implies the column is groupable.
     *
     * @param  list<DateBucket|string>  $buckets
     */
    public function groupableBy(array $buckets): self
    {
        $this->dateBuckets = $this->normalize($buckets, DateBucket::class, 'date bucket');
        $this->groupable = true;

        return $this;
    }

    /**
     * Declare the closed set of values this column may hold.
     *
     * @param  list<string>  $values
     */
    public function enum(array $values): self
    {
        $this->enum = $values;

        return $this;
    }

    /**
     * Attach unit metadata so a narrating agent formats the value correctly.
     */
    public function measuredIn(string $unit): self
    {
        $this->unit = $unit;

        return $this;
    }

    /**
     * Hide the column entirely unless the callback passes.
     *
     * A column hidden this way is absent from the contract handed to the agent,
     * so the agent never learns it exists.
     *
     * @param  Closure(Authenticatable|null): bool  $callback
     */
    public function visibleWhen(Closure $callback): self
    {
        $this->visibility = $callback;

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function exposedName(): string
    {
        return $this->alias ?? $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function isSelectable(): bool
    {
        return $this->selectable;
    }

    public function isFilterable(): bool
    {
        return $this->operators !== [];
    }

    public function isSortable(): bool
    {
        return $this->sortable;
    }

    public function isGroupable(): bool
    {
        return $this->groupable;
    }

    /** @return list<Operator> */
    public function operators(): array
    {
        return $this->operators;
    }

    /** @return list<Aggregate> */
    public function aggregates(): array
    {
        return $this->aggregates;
    }

    /** @return list<DateBucket> */
    public function dateBuckets(): array
    {
        return $this->dateBuckets;
    }

    /** @return list<string>|null */
    public function enumValues(): ?array
    {
        return $this->enum;
    }

    public function unit(): ?string
    {
        return $this->unit;
    }

    public function allowsOperator(Operator|string $operator): bool
    {
        $operator = $operator instanceof Operator ? $operator : Operator::tryFrom($operator);

        return $operator !== null && in_array($operator, $this->operators, strict: true);
    }

    public function allowsAggregate(Aggregate|string $aggregate): bool
    {
        $aggregate = $aggregate instanceof Aggregate ? $aggregate : Aggregate::tryFrom($aggregate);

        return $aggregate !== null && in_array($aggregate, $this->aggregates, strict: true);
    }

    public function allowsBucket(DateBucket|string $bucket): bool
    {
        $bucket = $bucket instanceof DateBucket ? $bucket : DateBucket::tryFrom($bucket);

        return $bucket !== null && in_array($bucket, $this->dateBuckets, strict: true);
    }

    public function isVisibleTo(?Authenticatable $user): bool
    {
        if ($this->visibility === null) {
            return true;
        }

        return ($this->visibility)($user);
    }

    /**
     * Convert developer-supplied strings into enum cases, failing loudly on typos.
     *
     * @template TEnum of Aggregate|DateBucket|Operator
     *
     * @param  list<TEnum|string>  $values
     * @param  class-string<TEnum>  $enum
     * @return list<TEnum>
     */
    private function normalize(array $values, string $enum, string $kind): array
    {
        $normalized = [];

        foreach ($values as $value) {
            if (is_string($value)) {
                $case = $enum::tryFrom($value);

                if ($case === null) {
                    throw SchemaDefinitionException::unknownValue(
                        $kind,
                        $value,
                        array_column($enum::cases(), 'value'),
                    );
                }
            } else {
                $case = $value;
            }

            if (! in_array($case, $normalized, strict: true)) {
                $normalized[] = $case;
            }
        }

        return $normalized;
    }
}
