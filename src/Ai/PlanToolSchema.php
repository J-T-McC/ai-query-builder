<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use JTMcC\AiQueryBuilder\Contract\SchemaContract;
use JTMcC\AiQueryBuilder\Plan\Enums\LogicalOperator;
use JTMcC\AiQueryBuilder\Plan\Enums\SortDirection;
use JTMcC\AiQueryBuilder\Schema\ColumnDefinition;

/**
 * The plan schema as Laravel JsonSchema types.
 *
 * Usable anywhere the AI SDK wants a schema: a Tool's `schema()`, or an agent
 * implementing `HasStructuredOutput`.
 *
 * It describes a plan for one resource and does not include the resource name.
 * Which resource is being queried is the caller's decision, not the model's, so
 * the caller sets it on the plan before running it — QueryDataTool does exactly
 * that. `SchemaContract::toJsonSchema()` is the rendering to use when a plan has
 * to travel on its own and name its own resource.
 *
 * Laravel's JsonSchema builder has no `$ref`, so a recursive filter tree cannot
 * be expressed the way SchemaContract::toJsonSchema does it. Groups are inlined
 * to a fixed depth instead: the innermost level accepts only conditions. That
 * bounds what a model can emit, not what the validator accepts — the schema's
 * depth and the schema's `maxFilterDepth` are separate numbers, and the
 * validator remains the authority.
 */
final readonly class PlanToolSchema
{
    /**
     * How deeply filter groups are inlined when no depth is given.
     */
    public const int DEFAULT_FILTER_DEPTH = 3;

    public function __construct(
        private SchemaContract $contract,
        private int $filterDepth = self::DEFAULT_FILTER_DEPTH,
        private PlanSchemaDetail $detail = PlanSchemaDetail::Enumerated,
    ) {}

    /**
     * @return array<string, Type>
     */
    public function build(JsonSchema $schema): array
    {
        $data = $this->contract->toArray();
        $columns = $this->contract->columns();

        $selectable = $this->pathsWhere($columns, static fn (ColumnDefinition $c): bool => $c->isSelectable());
        $aggregatable = $this->pathsWhere($columns, static fn (ColumnDefinition $c): bool => $c->aggregates() !== []);
        $filterable = $this->pathsWhere($columns, static fn (ColumnDefinition $c): bool => $c->isFilterable());
        $groupable = $this->pathsWhere($columns, static fn (ColumnDefinition $c): bool => $c->isGroupable());
        $sortable = $this->pathsWhere($columns, static fn (ColumnDefinition $c): bool => $c->isSortable());

        // No `resource` property. The caller knows which resource it built this
        // schema for and sets it on the plan itself, so asking the model to
        // echo a single-value enum back is bytes on every step for nothing.
        $properties = [
            'select' => $schema->array()
                ->items($this->select($schema, array_values(array_unique([...$selectable, ...$aggregatable])), $columns))
                ->min(1)
                ->description('What to return. At least one entry.')
                ->required(),
        ];

        if ($filterable !== []) {
            $properties['filters'] = $this->filterGroup($schema, $filterable, $columns, $this->filterDepth)
                ->description('Conditions rows must satisfy.');
        }

        if ($groupable !== []) {
            $properties['group_by'] = $schema->array()
                ->items($this->groupBy($schema, $groupable, $columns))
                ->description('Aggregate within these groupings.');
        }

        if ($aggregatable !== []) {
            $properties['having'] = $schema->array()
                ->items($this->having($schema))
                ->description('Conditions applied after aggregation.');
        }

        if ($sortable !== [] || $aggregatable !== []) {
            $properties['sort'] = $schema->array()->items($this->sort($schema));
        }

        $properties['limit'] = $schema->integer()
            ->min(1)
            ->max($data['limits']['max_rows'])
            ->description(sprintf('Maximum rows. Defaults to %d.', $data['limits']['default_rows']));

        return $properties;
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, ColumnDefinition>  $definitions
     */
    private function select(JsonSchema $schema, array $columns, array $definitions): Type
    {
        $properties = [
            'column' => $this->columnRef($schema, $columns, 'A selectable column path from the resource description.')->required(),
            'as' => $schema->string()->description('Result key. Defaults to the column path.'),
        ];

        $functions = $this->unionOf($definitions, static fn (ColumnDefinition $c): array => array_column($c->aggregates(), 'value'));

        if ($functions !== []) {
            $properties['function'] = $this->optionRef(
                $schema,
                $functions,
                'Aggregate to apply. Omit to return the raw value. Only permitted on some columns.',
            );
        }

        return $schema->object($properties)->withoutAdditionalProperties();
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, ColumnDefinition>  $definitions
     */
    private function filterGroup(JsonSchema $schema, array $columns, array $definitions, int $depth): Type
    {
        $condition = $this->condition($schema, $columns, $definitions);

        $items = $depth > 1
            ? $schema->anyOf([$condition, $this->filterGroup($schema, $columns, $definitions, $depth - 1)])
            : $condition;

        return $schema->object([
            'operator' => $schema->string()->enum(array_column(LogicalOperator::cases(), 'value'))->required(),
            'conditions' => $schema->array()->items($items)->min(1)->required(),
        ])->withoutAdditionalProperties();
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, ColumnDefinition>  $definitions
     */
    private function condition(JsonSchema $schema, array $columns, array $definitions): Type
    {
        $operators = $this->unionOf($definitions, static fn (ColumnDefinition $c): array => array_column($c->operators(), 'value'));

        if ($this->contract->windows() !== []) {
            $operators[] = 'within';
        }

        return $schema->object([
            'column' => $this->columnRef($schema, $columns, 'A filterable column path from the resource description.')->required(),
            'operator' => $this->optionRef($schema, $operators, 'Only some operators are permitted on each column.')->required(),
            'value' => $this->value($schema),
        ])->withoutAdditionalProperties();
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, ColumnDefinition>  $definitions
     */
    private function groupBy(JsonSchema $schema, array $columns, array $definitions): Type
    {
        $properties = [
            'column' => $this->columnRef($schema, $columns, 'A groupable column path from the resource description.')->required(),
        ];

        $buckets = $this->unionOf($definitions, static fn (ColumnDefinition $c): array => array_column($c->dateBuckets(), 'value'));

        if ($buckets !== []) {
            $properties['bucket'] = $this->optionRef($schema, $buckets, 'Truncate a date column to this granularity.');
        }

        return $schema->object($properties)->withoutAdditionalProperties();
    }

    private function having(JsonSchema $schema): Type
    {
        return $schema->object([
            'column' => $schema->string()
                ->description('The "as" alias of an aggregated select entry.')
                ->required(),
            'operator' => $schema->string()
                ->enum(['=', '!=', '>', '>=', '<', '<=', 'between', 'is_null', 'is_not_null'])
                ->required(),
            'value' => $this->value($schema),
        ])->withoutAdditionalProperties();
    }

    private function sort(JsonSchema $schema): Type
    {
        return $schema->object([
            'column' => $schema->string()
                ->description('A sortable column path, or the "as" alias of a select entry.')
                ->required(),
            'direction' => $schema->string()->enum(array_column(SortDirection::cases(), 'value')),
        ])->withoutAdditionalProperties();
    }

    private function value(JsonSchema $schema): Type
    {
        return $schema->anyOf([
            $schema->string(),
            $schema->number(),
            $schema->boolean(),
            $schema->array(),
        ])->description('A scalar, or a list for in, not_in and between. Omitted for null checks.');
    }

    /**
     * A column path: enumerated, or free-form with a pointer to the dictionary.
     *
     * The enum is the larger half of this schema and it is repeated at every
     * inlined filter level, so it is the first thing worth giving up when a
     * contract grows. Nothing about validation changes either way.
     *
     * @param  list<string>  $paths
     */
    private function columnRef(JsonSchema $schema, array $paths, string $hint): Type
    {
        return $this->detail->enumerates()
            ? $schema->string()->enum($paths)
            : $schema->string()->description($hint);
    }

    /**
     * A fixed option: enumerated, with a description that stands either way.
     *
     * @param  list<string>  $options
     */
    private function optionRef(JsonSchema $schema, array $options, string $description): Type
    {
        $type = $schema->string()->description($description);

        return $this->detail->enumerates() ? $type->enum($options) : $type;
    }

    /**
     * @param  array<string, ColumnDefinition>  $columns
     * @param  callable(ColumnDefinition): bool  $predicate
     * @return list<string>
     */
    private function pathsWhere(array $columns, callable $predicate): array
    {
        return array_keys(array_filter($columns, $predicate));
    }

    /**
     * @param  array<string, ColumnDefinition>  $columns
     * @param  callable(ColumnDefinition): list<string>  $extract
     * @return list<string>
     */
    private function unionOf(array $columns, callable $extract): array
    {
        $values = [];

        foreach ($columns as $column) {
            foreach ($extract($column) as $value) {
                $values[$value] = true;
            }
        }

        return array_keys($values);
    }
}
