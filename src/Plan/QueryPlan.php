<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Plan;

/**
 * A validated query plan.
 *
 * Constructing one of these is only possible through PlanValidator, so holding
 * a QueryPlan is proof that every token in it was checked against the schema.
 * Every clause carries its resolved ColumnDefinition, so the compiler never
 * touches a raw string that came from an agent.
 */
final readonly class QueryPlan
{
    /**
     * @param  list<SelectClause>  $select
     * @param  list<GroupByClause>  $groupBy
     * @param  list<HavingClause>  $having
     * @param  list<SortClause>  $sort
     */
    public function __construct(
        public string $resource,
        public array $select,
        public ?FilterGroup $filters,
        public array $groupBy,
        public array $having,
        public array $sort,
        public int $limit,
    ) {}

    public function isAggregated(): bool
    {
        foreach ($this->select as $clause) {
            if ($clause->isAggregated()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every relation path this plan traverses, deduplicated.
     *
     * The compiler joins exactly these and nothing else.
     *
     * @return list<string>
     */
    public function relationPaths(): array
    {
        $paths = [];

        foreach ($this->columnPaths() as $path) {
            $segments = explode('.', $path);
            array_pop($segments);

            for ($depth = 1; $depth <= count($segments); $depth++) {
                $paths[implode('.', array_slice($segments, 0, $depth))] = true;
            }
        }

        return array_keys($paths);
    }

    /**
     * Round-trips to the input shape, for auditing and deterministic replay.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $plan = [
            'resource' => $this->resource,
            'select' => array_map(
                static fn (SelectClause $clause): array => $clause->toArray(),
                $this->select,
            ),
        ];

        if ($this->filters !== null) {
            $plan['filters'] = $this->filters->toArray();
        }

        if ($this->groupBy !== []) {
            $plan['group_by'] = array_map(
                static fn (GroupByClause $clause): array => $clause->toArray(),
                $this->groupBy,
            );
        }

        if ($this->having !== []) {
            $plan['having'] = array_map(
                static fn (HavingClause $clause): array => $clause->toArray(),
                $this->having,
            );
        }

        if ($this->sort !== []) {
            $plan['sort'] = array_map(
                static fn (SortClause $clause): array => $clause->toArray(),
                $this->sort,
            );
        }

        $plan['limit'] = $this->limit;

        return $plan;
    }

    /**
     * Column paths referenced anywhere in the plan.
     *
     * @return list<string>
     */
    private function columnPaths(): array
    {
        $paths = array_map(
            static fn (SelectClause $clause): string => $clause->path,
            $this->select,
        );

        foreach ($this->groupBy as $clause) {
            $paths[] = $clause->path;
        }

        foreach ($this->sort as $clause) {
            if (! $clause->isAlias()) {
                $paths[] = $clause->reference;
            }
        }

        if ($this->filters !== null) {
            $paths = [...$paths, ...$this->filterPaths($this->filters)];
        }

        return array_values(array_unique($paths));
    }

    /** @return list<string> */
    private function filterPaths(FilterGroup $group): array
    {
        $paths = [];

        foreach ($group->conditions as $condition) {
            if ($condition instanceof FilterGroup) {
                $paths = [...$paths, ...$this->filterPaths($condition)];

                continue;
            }

            $paths[] = $condition->path;
        }

        return $paths;
    }
}
