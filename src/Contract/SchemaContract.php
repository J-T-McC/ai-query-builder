<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Contract;

use Illuminate\Contracts\Auth\Authenticatable;
use JTMcC\AiQueryBuilder\Plan\TimeWindow;
use JTMcC\AiQueryBuilder\Schema\ColumnDefinition;
use JTMcC\AiQueryBuilder\Schema\Limits;
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

    public function limits(): Limits
    {
        return $this->schema->limits();
    }

    /**
     * The data dictionary as structured data.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $limits = $this->limits();
        $columns = [];

        foreach ($this->columns() as $path => $column) {
            $columns[$path] = array_filter([
                'description' => $column->description(),
                'type' => $this->schema->typeOf($path, $column)?->value,
                'unit' => $column->unit(),
                'values' => $column->enumValues(),
                'selectable' => $column->isSelectable(),
                'sortable' => $column->isSortable(),
                'groupable' => $column->isGroupable(),
                'filters' => $column->isFilterable() ? $this->operatorsFor($path, $column) : [],
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
     * A stable hash of everything this contract exposes.
     *
     * Two contracts that would tell an agent the same thing hash the same, and
     * anything that changes what a user is told — a schema edit, a `visibleWhen`
     * that now resolves differently — changes the hash.
     *
     * That makes two things testable. A provider only serves a cached prompt
     * prefix when it is byte-identical between requests, so a stable
     * fingerprint is evidence the payload is cacheable at all. And it is the
     * cache key for anything that stores a plan: a plan generated against one
     * contract must not be replayed against another.
     */
    public function fingerprint(): string
    {
        return hash('sha256', (string) json_encode($this->toArray()));
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
        $limits = $this->limits();

        $lines = ['Resource: '.$this->schema->resourceName()];

        if ($this->schema->description() !== null) {
            $lines[] = $this->schema->description();
        }

        $lines[] = '';
        $lines[] = 'Columns — reference these names exactly. Anything not listed does not exist.';
        $lines = [...$lines, ...$this->columnBlock()];

        if ($this->windows() !== []) {
            $lines[] = '';
            $lines[] = 'Date ranges: operator "within" with one of: '.implode(', ', TimeWindow::names())
                .', or last_<N>_<seconds|minutes|hours|days|weeks|months|years>. Resolved here — do not '
                .'compute dates yourself. Literals also work: 2026-07-07, or 2026-07-07 09:30:00.';
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

    /**
     * The visible columns that accept a named date range.
     *
     * Gates the sentence describing them, so a resource with no such column
     * does not pay for it on every step.
     *
     * @return list<string>
     */
    public function windows(): array
    {
        $paths = [];

        foreach ($this->columns() as $path => $column) {
            if ($this->schema->permitsWindow($path, $column)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * The operators a column really accepts, including the one it derives.
     *
     * @return list<string>
     */
    private function operatorsFor(string $path, ColumnDefinition $column): array
    {
        $operators = array_column($column->operators(), 'value');

        return $this->schema->permitsWindow($path, $column) ? [...$operators, 'within'] : $operators;
    }

    /**
     * The column list, with the most common capability set stated once if that
     * comes out shorter.
     *
     * Which it does not always: a legend is a fixed cost amortised over column
     * count, and a resource whose columns all differ pays it for nothing. So
     * both renderings are measured and the shorter wins, rather than guessing a
     * threshold. Both are deterministic, which matters — a provider only serves
     * a cached prompt prefix that is byte-identical between requests.
     *
     * @return list<string>
     */
    private function columnBlock(): array
    {
        $plain = $this->columnLines(null);
        $common = $this->commonCapabilities();

        if ($common === null) {
            return $plain;
        }

        $hoisted = [
            'Unless a column lists its own capabilities, it supports: '.$common.'.',
            ...$this->columnLines($common),
        ];

        return strlen(implode("\n", $hoisted)) < strlen(implode("\n", $plain)) ? $hoisted : $plain;
    }

    /**
     * @return list<string>
     */
    private function columnLines(?string $hoisted): array
    {
        $lines = [];

        foreach ($this->columns() as $path => $column) {
            $lines[] = '- '.$path.$this->describeColumn($path, $column, $hoisted);
        }

        return $lines;
    }

    /**
     * The capability set shared by more visible columns than any other, or null
     * when no set is shared at all.
     */
    private function commonCapabilities(): ?string
    {
        $counts = [];

        foreach ($this->columns() as $path => $column) {
            $capabilities = $this->capabilitiesOf($path, $column);

            if ($capabilities !== '') {
                $counts[$capabilities] = ($counts[$capabilities] ?? 0) + 1;
            }
        }

        // First past the post, so declaration order breaks ties and the result
        // does not depend on sort stability.
        $best = null;
        $bestCount = 1;

        foreach ($counts as $capabilities => $count) {
            if ($count > $bestCount) {
                $best = (string) $capabilities;
                $bestCount = $count;
            }
        }

        return $best;
    }

    private function describeColumn(string $path, ColumnDefinition $column, ?string $hoisted): string
    {
        $parts = [];

        if ($column->description() !== null) {
            // Parts are joined with '. ', and ending a description with a full
            // stop is the obvious thing to write.
            $parts[] = rtrim($column->description(), '.');
        }

        // Only where it changes what the agent may write: a type it cannot act
        // on is a token cost on every step for nothing.
        $type = $column->isFilterable() ? $this->schema->typeOf($path, $column) : null;

        if ($type !== null && $type->constrainsValues()) {
            $parts[] = $type->value;
        }

        if ($column->unit() !== null) {
            $parts[] = 'unit: '.$column->unit();
        }

        if ($column->enumValues() !== null) {
            $parts[] = 'one of: '.implode(', ', $column->enumValues());
        }

        $capabilities = $this->capabilitiesOf($path, $column);

        // A column matching the hoisted set says nothing about capabilities;
        // the legend already did.
        if ($capabilities !== $hoisted) {
            $parts[] = $capabilities;
        }

        $parts = array_filter($parts);

        return $parts === [] ? '' : ' — '.implode('. ', $parts);
    }

    private function capabilitiesOf(string $path, ColumnDefinition $column): string
    {
        $capabilities = [];

        if ($column->isSelectable()) {
            $capabilities[] = 'select';
        }

        if ($column->operators() !== []) {
            $capabilities[] = 'filter('.implode(' ', $this->operatorsFor($path, $column)).')';
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

        return implode(', ', $capabilities);
    }
}
