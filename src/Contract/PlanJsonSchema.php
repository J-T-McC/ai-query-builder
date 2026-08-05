<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Contract;

use JTMcC\AiQueryBuilder\Plan\Enums\LogicalOperator;
use JTMcC\AiQueryBuilder\Plan\Enums\SortDirection;
use JTMcC\AiQueryBuilder\Schema\ColumnDefinition;

/**
 * A JSON Schema describing every plan this resource will accept.
 *
 * Constrains the model at the decoding layer, so an unusable plan is often
 * never generated rather than generated and rejected.
 *
 * Deliberately not expressed here: which operators go with which column. Doing
 * that needs a `oneOf` branch per column, and the schema grows past the point
 * where models handle it well. Column-level precision lives in the prompt text
 * (SchemaContract::toPrompt) and is enforced by the validator, which returns a
 * correctable error naming the exact path. This schema narrows the space; it is
 * not the security boundary.
 */
final readonly class PlanJsonSchema
{
    public function __construct(private SchemaContract $contract) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = $this->contract->toArray();
        $columns = $this->contract->columns();

        $selectable = $this->pathsWhere($columns, static fn (ColumnDefinition $c): bool => $c->isSelectable());
        $aggregatable = $this->pathsWhere($columns, static fn (ColumnDefinition $c): bool => $c->aggregates() !== []);
        $filterable = $this->pathsWhere($columns, static fn (ColumnDefinition $c): bool => $c->isFilterable());
        $groupable = $this->pathsWhere($columns, static fn (ColumnDefinition $c): bool => $c->isGroupable());
        $sortable = $this->pathsWhere($columns, static fn (ColumnDefinition $c): bool => $c->isSortable());

        $properties = [
            'resource' => ['type' => 'string', 'const' => $data['resource']],
            'select' => [
                'type' => 'array',
                'minItems' => 1,
                'description' => 'What to return. At least one entry.',
                'items' => ['$ref' => '#/$defs/select'],
            ],
        ];

        $defs = [
            'select' => $this->selectDef(
                array_values(array_unique([...$selectable, ...$aggregatable])),
                $this->valuesOf($columns, static fn (ColumnDefinition $c): array => array_column($c->aggregates(), 'value')),
            ),
        ];

        if ($filterable !== []) {
            $properties['filters'] = ['$ref' => '#/$defs/filter_group'];
            $defs['filter_group'] = $this->filterGroupDef();
            $defs['condition'] = $this->conditionDef(
                $filterable,
                $this->valuesOf($columns, static fn (ColumnDefinition $c): array => array_column($c->operators(), 'value')),
            );
        }

        if ($groupable !== []) {
            $properties['group_by'] = ['type' => 'array', 'items' => ['$ref' => '#/$defs/group_by']];
            $defs['group_by'] = $this->groupByDef(
                $groupable,
                $this->valuesOf($columns, static fn (ColumnDefinition $c): array => array_column($c->dateBuckets(), 'value')),
            );
        }

        if ($aggregatable !== []) {
            $properties['having'] = ['type' => 'array', 'items' => ['$ref' => '#/$defs/having']];
            $defs['having'] = $this->havingDef();
        }

        if ($sortable !== [] || $aggregatable !== []) {
            $properties['sort'] = ['type' => 'array', 'items' => ['$ref' => '#/$defs/sort']];
            $defs['sort'] = $this->sortDef();
        }

        $properties['limit'] = [
            'type' => 'integer',
            'minimum' => 1,
            'maximum' => $data['limits']['max_rows'],
            'description' => sprintf('Defaults to %d.', $data['limits']['default_rows']),
        ];

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'description' => $data['description'] ?? null,
            'additionalProperties' => false,
            'required' => ['resource', 'select'],
            'properties' => $properties,
            '$defs' => $defs,
        ];
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $functions
     * @return array<string, mixed>
     */
    private function selectDef(array $columns, array $functions): array
    {
        $properties = [
            'column' => ['type' => 'string', 'enum' => $columns],
            'as' => [
                'type' => 'string',
                'pattern' => '^[a-zA-Z_][a-zA-Z0-9_]*$',
                'description' => 'Result key. Defaults to the column path.',
            ],
        ];

        if ($functions !== []) {
            $properties['function'] = [
                'type' => 'string',
                'enum' => $functions,
                'description' => 'Aggregate to apply. Omit to return the raw value.',
            ];
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['column'],
            'properties' => $properties,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filterGroupDef(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['operator', 'conditions'],
            'properties' => [
                'operator' => ['type' => 'string', 'enum' => array_column(LogicalOperator::cases(), 'value')],
                'conditions' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'anyOf' => [
                            ['$ref' => '#/$defs/condition'],
                            ['$ref' => '#/$defs/filter_group'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $operators
     * @return array<string, mixed>
     */
    private function conditionDef(array $columns, array $operators): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['column', 'operator'],
            'properties' => [
                'column' => ['type' => 'string', 'enum' => $columns],
                'operator' => ['type' => 'string', 'enum' => $operators],
                'value' => $this->valueDef(),
            ],
        ];
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $buckets
     * @return array<string, mixed>
     */
    private function groupByDef(array $columns, array $buckets): array
    {
        $properties = ['column' => ['type' => 'string', 'enum' => $columns]];

        if ($buckets !== []) {
            $properties['bucket'] = [
                'type' => 'string',
                'enum' => $buckets,
                'description' => 'Truncate a date column to this granularity.',
            ];
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['column'],
            'properties' => $properties,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function havingDef(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['column', 'operator'],
            'description' => 'Filters applied after aggregation.',
            'properties' => [
                'column' => [
                    'type' => 'string',
                    'description' => 'The "as" alias of an aggregated select entry.',
                ],
                'operator' => [
                    'type' => 'string',
                    'enum' => ['=', '!=', '>', '>=', '<', '<=', 'between', 'is_null', 'is_not_null'],
                ],
                'value' => $this->valueDef(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sortDef(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['column'],
            'properties' => [
                'column' => [
                    'type' => 'string',
                    'description' => 'A sortable column path, or the "as" alias of a select entry.',
                ],
                'direction' => [
                    'type' => 'string',
                    'enum' => array_column(SortDirection::cases(), 'value'),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function valueDef(): array
    {
        return [
            'description' => 'A scalar, or a list for in/not_in and between. Omitted for null checks.',
            'anyOf' => [
                ['type' => 'string'],
                ['type' => 'number'],
                ['type' => 'boolean'],
                ['type' => 'array'],
            ],
        ];
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
     * The union of some per-column capability across every visible column.
     *
     * @param  array<string, ColumnDefinition>  $columns
     * @param  callable(ColumnDefinition): list<string>  $extract
     * @return list<string>
     */
    private function valuesOf(array $columns, callable $extract): array
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
