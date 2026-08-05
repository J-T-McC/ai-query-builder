<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Execution;

/**
 * The rows a plan returned, plus enough context to narrate them correctly.
 *
 * Column metadata travels with the rows because a number without its unit is
 * how a model ends up reporting cents as dollars. `truncated` is explicit for
 * the same reason: a silently capped result reads as a complete answer.
 */
final readonly class ResultSet
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, array{unit?: string, description?: string}>  $columns  Keyed by alias.
     */
    public function __construct(
        public string $resource,
        public array $rows,
        public array $columns,
        public bool $truncated,
        public float $durationMs,
    ) {}

    public function count(): int
    {
        return count($this->rows);
    }

    /**
     * @return array{
     *     resource: string,
     *     columns: array<string, array{unit?: string, description?: string}>,
     *     rows: list<array<string, mixed>>,
     *     row_count: int,
     *     truncated: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'resource' => $this->resource,
            'columns' => $this->columns,
            'rows' => $this->rows,
            'row_count' => $this->count(),
            'truncated' => $this->truncated,
        ];
    }

    /**
     * The shape handed back to an AI layer. Duration is deliberately excluded:
     * it is audit data, not something a model should narrate.
     */
    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR);
    }
}
