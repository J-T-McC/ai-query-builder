<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Contract;

use Illuminate\Contracts\Auth\Authenticatable;
use JTMcC\AiQueryBuilder\Schema\ColumnDefinition;
use JTMcC\AiQueryBuilder\Schema\ResourceSchema;

/**
 * What an agent is told about a resource.
 *
 * Built per user: a column gated by `visibleWhen` is absent here entirely, so
 * an agent acting for one user never learns a column exists for another.
 *
 * There are two renderings, and they do different jobs. The JSON Schema
 * constrains what the model can emit at the decoding layer. The prompt text
 * carries the per-column detail — which operators suit which column, what a
 * column means, what units it is in — that steers the model toward a plan that
 * will pass validation.
 */
final readonly class SchemaContract
{
    public function __construct(
        private ResourceSchema $schema,
        private ?Authenticatable $user = null,
    ) {}

    public static function for(ResourceSchema $schema, ?Authenticatable $user = null): self
    {
        return new self($schema, $user);
    }

    /**
     * @return array<string, ColumnDefinition>
     */
    public function columns(): array
    {
        return $this->schema->visibleColumnMap($this->user);
    }

    /**
     * The data dictionary as structured data.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $limits = $this->schema->limits();
        $columns = [];

        foreach ($this->columns() as $path => $column) {
            $columns[$path] = array_filter([
                'description' => $column->description(),
                'unit' => $column->unit(),
                'values' => $column->enumValues(),
                'selectable' => $column->isSelectable(),
                'sortable' => $column->isSortable(),
                'groupable' => $column->isGroupable(),
                'filters' => array_column($column->operators(), 'value'),
                'aggregates' => array_column($column->aggregates(), 'value'),
                'buckets' => array_column($column->dateBuckets(), 'value'),
            ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== false);
        }

        return [
            'resource' => $this->schema->resourceName(),
            'description' => $this->schema->description(),
            'columns' => $columns,
            'limits' => [
                'default_rows' => $limits->default,
                'max_rows' => $limits->max,
                'max_relation_depth' => $limits->maxRelationDepth,
            ],
        ];
    }

    /**
     * A JSON Schema constraining every plan this resource accepts.
     *
     * @return array<string, mixed>
     */
    public function toJsonSchema(): array
    {
        return (new PlanJsonSchema($this))->toArray();
    }

    /**
     * The data dictionary as text, for a tool description or system prompt.
     */
    public function toPrompt(): string
    {
        $limits = $this->schema->limits();

        $lines = ['Resource: '.$this->schema->resourceName()];

        if ($this->schema->description() !== null) {
            $lines[] = $this->schema->description();
        }

        $lines[] = '';
        $lines[] = 'Columns — reference these names exactly. Anything not listed does not exist.';

        foreach ($this->columns() as $path => $column) {
            $lines[] = '- '.$path.$this->describeColumn($column);
        }

        $lines[] = '';
        $lines[] = sprintf(
            'Returns %d rows unless a limit is given, up to %d. Relation paths may traverse at most %d relations.',
            $limits->default,
            $limits->max,
            $limits->maxRelationDepth,
        );

        return implode("\n", $lines);
    }

    private function describeColumn(ColumnDefinition $column): string
    {
        $parts = [];

        if ($column->description() !== null) {
            $parts[] = $column->description();
        }

        if ($column->unit() !== null) {
            $parts[] = 'unit: '.$column->unit();
        }

        if ($column->enumValues() !== null) {
            $parts[] = 'one of: '.implode(', ', $column->enumValues());
        }

        $capabilities = [];

        if ($column->isSelectable()) {
            $capabilities[] = 'select';
        }

        if ($column->operators() !== []) {
            $capabilities[] = 'filter('.implode(' ', array_column($column->operators(), 'value')).')';
        }

        if ($column->aggregates() !== []) {
            $capabilities[] = 'aggregate('.implode(' ', array_column($column->aggregates(), 'value')).')';
        }

        if ($column->dateBuckets() !== []) {
            $capabilities[] = 'group by('.implode(' ', array_column($column->dateBuckets(), 'value')).')';
        } elseif ($column->isGroupable()) {
            $capabilities[] = 'group';
        }

        if ($column->isSortable()) {
            $capabilities[] = 'sort';
        }

        $parts[] = implode(', ', $capabilities);

        return ' — '.implode('. ', array_filter($parts));
    }
}
