<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Validation;

use Illuminate\Contracts\Auth\Authenticatable;
use JTMcC\AiQueryBuilder\Exceptions\InvalidQueryPlanException;
use JTMcC\AiQueryBuilder\Plan\Enums\LogicalOperator;
use JTMcC\AiQueryBuilder\Plan\Enums\SortDirection;
use JTMcC\AiQueryBuilder\Plan\FilterCondition;
use JTMcC\AiQueryBuilder\Plan\FilterGroup;
use JTMcC\AiQueryBuilder\Plan\GroupByClause;
use JTMcC\AiQueryBuilder\Plan\HavingClause;
use JTMcC\AiQueryBuilder\Plan\QueryPlan;
use JTMcC\AiQueryBuilder\Plan\SelectClause;
use JTMcC\AiQueryBuilder\Plan\SortClause;
use JTMcC\AiQueryBuilder\Schema\ColumnDefinition;
use JTMcC\AiQueryBuilder\Schema\Enums\Aggregate;
use JTMcC\AiQueryBuilder\Schema\Enums\DateBucket;
use JTMcC\AiQueryBuilder\Schema\Enums\Operator;
use JTMcC\AiQueryBuilder\Schema\Limits;
use JTMcC\AiQueryBuilder\Schema\ResourceSchema;

/**
 * Turns an untrusted array into a validated QueryPlan, or refuses.
 *
 * Everything here fails closed. Unknown keys are errors rather than being
 * dropped, because silently discarding part of a plan answers a question the
 * user did not ask while looking like it answered the one they did.
 *
 * Errors accumulate rather than short-circuiting, so a plan can be corrected in
 * one pass instead of one round trip per mistake.
 */
final class PlanValidator
{
    /** @var list<string> */
    private const array ALLOWED_KEYS = ['resource', 'select', 'filters', 'group_by', 'having', 'sort', 'limit'];

    /** @var list<string> */
    private const array SELECT_KEYS = ['column', 'function', 'as'];

    /** @var list<string> */
    private const array GROUP_KEYS = ['operator', 'conditions'];

    /** @var list<string> */
    private const array CONDITION_KEYS = ['column', 'operator', 'value'];

    /** @var list<string> */
    private const array GROUP_BY_KEYS = ['column', 'bucket'];

    /** @var list<string> */
    private const array HAVING_KEYS = ['column', 'operator', 'value'];

    /** @var list<string> */
    private const array SORT_KEYS = ['column', 'direction'];

    /**
     * Operators that take no value at all.
     *
     * @var list<Operator>
     */
    private const array VALUELESS_OPERATORS = [Operator::IsNull, Operator::IsNotNull];

    /**
     * Operators whose value is a list rather than a scalar.
     *
     * @var list<Operator>
     */
    private const array LIST_OPERATORS = [Operator::In, Operator::NotIn, Operator::Between];

    /**
     * Operators permitted after aggregation.
     *
     * Set membership and pattern matching are excluded: they are not meaningful
     * against an aggregate, and supporting them would mean generating raw SQL
     * for the having clause.
     *
     * @var list<Operator>
     */
    private const array HAVING_OPERATORS = [
        Operator::Equals,
        Operator::NotEquals,
        Operator::GreaterThan,
        Operator::GreaterThanOrEqual,
        Operator::LessThan,
        Operator::LessThanOrEqual,
        Operator::Between,
        Operator::IsNull,
        Operator::IsNotNull,
    ];

    /** @var list<ValidationError> */
    private array $errors = [];

    /** @var list<string> */
    private array $candidates = [];

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws InvalidQueryPlanException
     */
    public function validate(array $input, ResourceSchema $schema, ?Authenticatable $user = null): QueryPlan
    {
        $this->errors = [];
        $this->candidates = $schema->columnPaths($user);

        $limits = $schema->limits();

        $this->rejectUnknownKeys($input, self::ALLOWED_KEYS, '');

        $resource = $this->validateResource($input, $schema);
        $select = $this->validateSelect($input['select'] ?? null, $schema, $user, $limits);
        $filters = $this->validateFilters($input['filters'] ?? null, $schema, $user, $limits);
        $groupBy = $this->validateGroupBy($input['group_by'] ?? null, $schema, $user, $limits);
        $having = $this->validateHaving($input['having'] ?? null, $select);
        $sort = $this->validateSort($input['sort'] ?? null, $schema, $user, $select, $limits);
        $limit = $this->validateLimit($input['limit'] ?? null, $limits);

        $this->assertNonAggregatedColumnsAreGrouped($select, $groupBy);

        if ($this->errors !== []) {
            throw new InvalidQueryPlanException($this->errors);
        }

        return new QueryPlan(
            resource: $resource,
            select: $select,
            filters: $filters,
            groupBy: $groupBy,
            having: $having,
            sort: $sort,
            limit: $limit,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function validateResource(array $input, ResourceSchema $schema): string
    {
        $name = $schema->resourceName() ?? '';
        $given = $input['resource'] ?? null;

        if ($given !== null && $given !== $name) {
            $this->error(
                'resource',
                ValidationCode::UnknownResource,
                sprintf('This plan targets [%s] but was validated against [%s].', $this->describe($given), $name),
            );
        }

        return $name;
    }

    /**
     * @return list<SelectClause>
     */
    private function validateSelect(mixed $select, ResourceSchema $schema, ?Authenticatable $user, Limits $limits): array
    {
        if (! is_array($select) || ! array_is_list($select) || $select === []) {
            $this->error(
                'select',
                $select === null || $select === [] ? ValidationCode::EmptySelect : ValidationCode::InvalidType,
                'A plan must select at least one column, as a list.',
            );

            return [];
        }

        $clauses = [];
        $aliases = [];

        foreach ($select as $index => $entry) {
            $path = "select.{$index}";

            if (! is_array($entry)) {
                $this->error($path, ValidationCode::InvalidType, 'Each select entry must be an object.');

                continue;
            }

            $this->rejectUnknownKeys($entry, self::SELECT_KEYS, $path);

            $column = $this->resolveColumn($entry['column'] ?? null, $schema, $user, $limits, "{$path}.column");

            if ($column === null) {
                continue;
            }

            [$columnPath, $definition] = $column;

            $function = null;

            if (isset($entry['function'])) {
                $function = $this->resolveAggregate($entry['function'], $definition, "{$path}.function");

                if ($function === null) {
                    continue;
                }
            } elseif (! $definition->isSelectable()) {
                $this->error(
                    "{$path}.column",
                    ValidationCode::ColumnNotSelectable,
                    sprintf('The column [%s] cannot be selected.', $columnPath),
                );

                continue;
            }

            $alias = $this->resolveAlias($entry['as'] ?? null, $columnPath, $function, "{$path}.as");

            if ($alias === null) {
                continue;
            }

            if (isset($aliases[$alias])) {
                $this->error(
                    "{$path}.as",
                    ValidationCode::DuplicateAlias,
                    sprintf('The alias [%s] is used more than once.', $alias),
                );

                continue;
            }

            $aliases[$alias] = true;
            $clauses[] = new SelectClause($columnPath, $definition, $function, $alias);
        }

        return $clauses;
    }

    private function validateFilters(mixed $filters, ResourceSchema $schema, ?Authenticatable $user, Limits $limits): ?FilterGroup
    {
        if ($filters === null) {
            return null;
        }

        $nodes = 0;

        return $this->validateFilterGroup($filters, $schema, $user, $limits, 'filters', 1, $nodes);
    }

    private function validateFilterGroup(
        mixed $group,
        ResourceSchema $schema,
        ?Authenticatable $user,
        Limits $limits,
        string $path,
        int $depth,
        int &$nodes,
    ): ?FilterGroup {
        if (! is_array($group)) {
            $this->error($path, ValidationCode::InvalidType, 'A filter group must be an object.');

            return null;
        }

        $this->rejectUnknownKeys($group, self::GROUP_KEYS, $path);

        if ($depth > $limits->maxFilterDepth) {
            $this->error(
                $path,
                ValidationCode::FilterTooComplex,
                sprintf('Filters may not nest deeper than %d levels.', $limits->maxFilterDepth),
            );

            return null;
        }

        $operator = LogicalOperator::tryFrom(is_string($group['operator'] ?? null) ? $group['operator'] : '');

        if ($operator === null) {
            $this->error(
                "{$path}.operator",
                ValidationCode::InvalidValueShape,
                'A filter group operator must be "and" or "or".',
            );

            return null;
        }

        $conditions = $group['conditions'] ?? null;

        if (! is_array($conditions) || ! array_is_list($conditions) || $conditions === []) {
            $this->error(
                "{$path}.conditions",
                ValidationCode::InvalidType,
                'A filter group must contain a non-empty list of conditions.',
            );

            return null;
        }

        $validated = [];

        foreach ($conditions as $index => $condition) {
            $nodes++;

            if ($nodes > $limits->maxFilterNodes) {
                $this->error(
                    $path,
                    ValidationCode::FilterTooComplex,
                    sprintf('Filters may not contain more than %d conditions.', $limits->maxFilterNodes),
                );

                return null;
            }

            $childPath = "{$path}.conditions.{$index}";

            // A nested group is identified structurally, so a condition can never
            // be mistaken for a group or vice versa.
            $child = is_array($condition) && array_key_exists('conditions', $condition)
                ? $this->validateFilterGroup($condition, $schema, $user, $limits, $childPath, $depth + 1, $nodes)
                : $this->validateCondition($condition, $schema, $user, $limits, $childPath);

            if ($child !== null) {
                $validated[] = $child;
            }
        }

        return $validated === [] ? null : new FilterGroup($operator, $validated);
    }

    private function validateCondition(
        mixed $condition,
        ResourceSchema $schema,
        ?Authenticatable $user,
        Limits $limits,
        string $path,
    ): ?FilterCondition {
        if (! is_array($condition)) {
            $this->error($path, ValidationCode::InvalidType, 'A filter condition must be an object.');

            return null;
        }

        $this->rejectUnknownKeys($condition, self::CONDITION_KEYS, $path);

        $column = $this->resolveColumn($condition['column'] ?? null, $schema, $user, $limits, "{$path}.column");

        if ($column === null) {
            return null;
        }

        [$columnPath, $definition] = $column;

        if (! $definition->isFilterable()) {
            $this->error(
                "{$path}.column",
                ValidationCode::ColumnNotFilterable,
                sprintf('The column [%s] cannot be filtered.', $columnPath),
            );

            return null;
        }

        $operator = Operator::tryFrom(is_string($condition['operator'] ?? null) ? $condition['operator'] : '');

        if ($operator === null || ! $definition->allowsOperator($operator)) {
            $this->error(
                "{$path}.operator",
                ValidationCode::OperatorNotAllowed,
                sprintf(
                    'The operator [%s] is not permitted on [%s]. Permitted: %s.',
                    $this->describe($condition['operator'] ?? null),
                    $columnPath,
                    implode(', ', array_column($definition->operators(), 'value')),
                ),
            );

            return null;
        }

        $value = $condition['value'] ?? null;

        if (! $this->valueShapeIsValid($operator, $value, "{$path}.value")) {
            return null;
        }

        if (! $this->valueIsWithinEnum($definition, $operator, $value, $columnPath, "{$path}.value")) {
            return null;
        }

        return new FilterCondition($columnPath, $definition, $operator, $value);
    }

    /**
     * @return list<GroupByClause>
     */
    private function validateGroupBy(mixed $groupBy, ResourceSchema $schema, ?Authenticatable $user, Limits $limits): array
    {
        if ($groupBy === null) {
            return [];
        }

        if (! is_array($groupBy) || ! array_is_list($groupBy)) {
            $this->error('group_by', ValidationCode::InvalidType, 'group_by must be a list.');

            return [];
        }

        $clauses = [];

        foreach ($groupBy as $index => $entry) {
            $path = "group_by.{$index}";

            if (! is_array($entry)) {
                $this->error($path, ValidationCode::InvalidType, 'Each group_by entry must be an object.');

                continue;
            }

            $this->rejectUnknownKeys($entry, self::GROUP_BY_KEYS, $path);

            $column = $this->resolveColumn($entry['column'] ?? null, $schema, $user, $limits, "{$path}.column");

            if ($column === null) {
                continue;
            }

            [$columnPath, $definition] = $column;

            if (! $definition->isGroupable()) {
                $this->error(
                    "{$path}.column",
                    ValidationCode::ColumnNotGroupable,
                    sprintf('The column [%s] cannot be grouped by.', $columnPath),
                );

                continue;
            }

            $bucket = null;

            if (isset($entry['bucket'])) {
                $bucket = DateBucket::tryFrom(is_string($entry['bucket']) ? $entry['bucket'] : '');

                if ($bucket === null || ! $definition->allowsBucket($bucket)) {
                    $this->error(
                        "{$path}.bucket",
                        ValidationCode::BucketNotAllowed,
                        sprintf(
                            'The bucket [%s] is not permitted on [%s]. Permitted: %s.',
                            $this->describe($entry['bucket']),
                            $columnPath,
                            implode(', ', array_column($definition->dateBuckets(), 'value')) ?: 'none',
                        ),
                    );

                    continue;
                }
            }

            $clauses[] = new GroupByClause($columnPath, $definition, $bucket);
        }

        return $clauses;
    }

    /**
     * @param  list<SelectClause>  $select
     * @return list<HavingClause>
     */
    private function validateHaving(mixed $having, array $select): array
    {
        if ($having === null) {
            return [];
        }

        if (! is_array($having) || ! array_is_list($having)) {
            $this->error('having', ValidationCode::InvalidType, 'having must be a list.');

            return [];
        }

        $aggregated = [];

        foreach ($select as $clause) {
            if ($clause->isAggregated()) {
                $aggregated[$clause->alias] = true;
            }
        }

        $clauses = [];

        foreach ($having as $index => $entry) {
            $path = "having.{$index}";

            if (! is_array($entry)) {
                $this->error($path, ValidationCode::InvalidType, 'Each having entry must be an object.');

                continue;
            }

            $this->rejectUnknownKeys($entry, self::HAVING_KEYS, $path);

            $alias = $entry['column'] ?? null;

            if (! is_string($alias) || ! isset($aggregated[$alias])) {
                $this->error(
                    "{$path}.column",
                    ValidationCode::UnknownAlias,
                    sprintf(
                        'having may only reference an aggregated select alias. Available: %s.',
                        implode(', ', array_keys($aggregated)) ?: 'none',
                    ),
                    $this->suggest(is_string($alias) ? $alias : '', array_keys($aggregated)),
                );

                continue;
            }

            $operator = Operator::tryFrom(is_string($entry['operator'] ?? null) ? $entry['operator'] : '');

            if ($operator === null || ! in_array($operator, self::HAVING_OPERATORS, strict: true)) {
                $this->error(
                    "{$path}.operator",
                    ValidationCode::OperatorNotAllowed,
                    sprintf(
                        'The operator [%s] is not permitted in having. Permitted: %s.',
                        $this->describe($entry['operator'] ?? null),
                        implode(', ', array_column(self::HAVING_OPERATORS, 'value')),
                    ),
                );

                continue;
            }

            $value = $entry['value'] ?? null;

            if (! $this->valueShapeIsValid($operator, $value, "{$path}.value")) {
                continue;
            }

            $clauses[] = new HavingClause($alias, $operator, $value);
        }

        return $clauses;
    }

    /**
     * @param  list<SelectClause>  $select
     * @return list<SortClause>
     */
    private function validateSort(
        mixed $sort,
        ResourceSchema $schema,
        ?Authenticatable $user,
        array $select,
        Limits $limits,
    ): array {
        if ($sort === null) {
            return [];
        }

        if (! is_array($sort) || ! array_is_list($sort)) {
            $this->error('sort', ValidationCode::InvalidType, 'sort must be a list.');

            return [];
        }

        $aliases = [];

        foreach ($select as $clause) {
            $aliases[$clause->alias] = true;
        }

        $clauses = [];

        foreach ($sort as $index => $entry) {
            $path = "sort.{$index}";

            if (! is_array($entry)) {
                $this->error($path, ValidationCode::InvalidType, 'Each sort entry must be an object.');

                continue;
            }

            $this->rejectUnknownKeys($entry, self::SORT_KEYS, $path);

            $direction = SortDirection::tryFrom(is_string($entry['direction'] ?? null) ? $entry['direction'] : 'asc');

            if ($direction === null) {
                $this->error(
                    "{$path}.direction",
                    ValidationCode::InvalidValueShape,
                    'A sort direction must be "asc" or "desc".',
                );

                continue;
            }

            $reference = $entry['column'] ?? null;

            // Sorting by a projected alias is how an aggregate is ordered, so an
            // alias match is checked before falling back to a schema column.
            if (is_string($reference) && isset($aliases[$reference])) {
                $clauses[] = new SortClause($reference, $direction);

                continue;
            }

            $column = $this->resolveColumn($reference, $schema, $user, $limits, "{$path}.column");

            if ($column === null) {
                continue;
            }

            [$columnPath, $definition] = $column;

            if (! $definition->isSortable()) {
                $this->error(
                    "{$path}.column",
                    ValidationCode::ColumnNotSortable,
                    sprintf('The column [%s] cannot be sorted by.', $columnPath),
                );

                continue;
            }

            $clauses[] = new SortClause($columnPath, $direction, $definition);
        }

        return $clauses;
    }

    private function validateLimit(mixed $limit, Limits $limits): int
    {
        if ($limit === null) {
            return $limits->default;
        }

        if (! is_int($limit) || $limit < 1 || $limit > $limits->max) {
            $this->error(
                'limit',
                ValidationCode::LimitOutOfRange,
                sprintf('limit must be an integer between 1 and %d.', $limits->max),
            );

            return $limits->default;
        }

        return $limit;
    }

    /**
     * Every non-aggregated column must be grouped once any aggregate is present.
     *
     * Caught here rather than left to the database so the failure arrives as a
     * correctable plan error instead of an opaque SQL error.
     *
     * @param  list<SelectClause>  $select
     * @param  list<GroupByClause>  $groupBy
     */
    private function assertNonAggregatedColumnsAreGrouped(array $select, array $groupBy): void
    {
        $hasAggregate = false;

        foreach ($select as $clause) {
            if ($clause->isAggregated()) {
                $hasAggregate = true;

                break;
            }
        }

        if (! $hasAggregate) {
            return;
        }

        $grouped = [];

        foreach ($groupBy as $clause) {
            $grouped[$clause->path] = true;
        }

        foreach ($select as $index => $clause) {
            if ($clause->isAggregated() || isset($grouped[$clause->path])) {
                continue;
            }

            $this->error(
                "select.{$index}.column",
                ValidationCode::UngroupedColumn,
                sprintf(
                    'The column [%s] is selected alongside an aggregate, so it must also appear in group_by.',
                    $clause->path,
                ),
            );
        }
    }

    /**
     * Resolve a column path against the schema as this user sees it.
     *
     * A column the user cannot see is reported as unknown rather than forbidden,
     * so a rejection never reveals that a hidden column exists.
     *
     * @return array{0: string, 1: ColumnDefinition}|null
     */
    private function resolveColumn(
        mixed $path,
        ResourceSchema $schema,
        ?Authenticatable $user,
        Limits $limits,
        string $errorPath,
    ): ?array {
        if (! is_string($path)) {
            $this->error($errorPath, ValidationCode::InvalidType, 'A column reference must be a string.');

            return null;
        }

        if ($schema->depthOf($path) > $limits->maxRelationDepth) {
            $this->error(
                $errorPath,
                ValidationCode::RelationDepthExceeded,
                sprintf('The path [%s] traverses more than %d relations.', $path, $limits->maxRelationDepth),
            );

            return null;
        }

        $column = $schema->findColumn($path);

        if ($column === null || ! $column->isVisibleTo($user)) {
            $this->error(
                $errorPath,
                ValidationCode::UnknownColumn,
                sprintf('Unknown column [%s].', $path),
                $this->suggest($path, $this->candidates),
            );

            return null;
        }

        return [$path, $column];
    }

    private function resolveAggregate(mixed $function, ColumnDefinition $column, string $errorPath): ?Aggregate
    {
        $aggregate = Aggregate::tryFrom(is_string($function) ? $function : '');

        if ($aggregate === null || ! $column->allowsAggregate($aggregate)) {
            $this->error(
                $errorPath,
                ValidationCode::FunctionNotAllowed,
                sprintf(
                    'The function [%s] is not permitted on this column. Permitted: %s.',
                    $this->describe($function),
                    implode(', ', array_column($column->aggregates(), 'value')) ?: 'none',
                ),
            );

            return null;
        }

        return $aggregate;
    }

    private function resolveAlias(mixed $alias, string $path, ?Aggregate $function, string $errorPath): ?string
    {
        if ($alias === null) {
            $base = str_replace('.', '_', $path);

            return $function === null ? $base : "{$function->value}_{$base}";
        }

        // Aliases surface in result keys and in having/sort references, so they
        // are constrained to a safe identifier shape.
        if (! is_string($alias) || preg_match('/^[a-z_][a-z0-9_]*$/i', $alias) !== 1) {
            $this->error(
                $errorPath,
                ValidationCode::InvalidValueShape,
                'An alias must start with a letter or underscore and contain only letters, digits and underscores.',
            );

            return null;
        }

        return $alias;
    }

    private function valueShapeIsValid(Operator $operator, mixed $value, string $errorPath): bool
    {
        if (in_array($operator, self::VALUELESS_OPERATORS, strict: true)) {
            if ($value !== null) {
                $this->error(
                    $errorPath,
                    ValidationCode::InvalidValueShape,
                    sprintf('The operator [%s] does not take a value.', $operator->value),
                );

                return false;
            }

            return true;
        }

        if (! in_array($operator, self::LIST_OPERATORS, strict: true)) {
            if (! $this->isScalar($value)) {
                $this->error(
                    $errorPath,
                    ValidationCode::InvalidValueShape,
                    sprintf('The operator [%s] requires a single scalar value.', $operator->value),
                );

                return false;
            }

            return true;
        }

        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            $this->error(
                $errorPath,
                ValidationCode::InvalidValueShape,
                sprintf('The operator [%s] requires a non-empty list of values.', $operator->value),
            );

            return false;
        }

        if ($operator === Operator::Between && count($value) !== 2) {
            $this->error(
                $errorPath,
                ValidationCode::InvalidValueShape,
                'The operator [between] requires exactly two values.',
            );

            return false;
        }

        foreach ($value as $item) {
            if (! $this->isScalar($item)) {
                $this->error(
                    $errorPath,
                    ValidationCode::InvalidValueShape,
                    sprintf('Every value for [%s] must be a scalar.', $operator->value),
                );

                return false;
            }
        }

        return true;
    }

    private function valueIsWithinEnum(
        ColumnDefinition $column,
        Operator $operator,
        mixed $value,
        string $columnPath,
        string $errorPath,
    ): bool {
        $allowed = $column->enumValues();

        if ($allowed === null || in_array($operator, self::VALUELESS_OPERATORS, strict: true)) {
            return true;
        }

        $values = is_array($value) ? $value : [$value];

        foreach ($values as $item) {
            if (in_array($item, $allowed, strict: true)) {
                continue;
            }

            $this->error(
                $errorPath,
                ValidationCode::ValueNotInEnum,
                sprintf(
                    'The value [%s] is not a permitted value for [%s]. Permitted: %s.',
                    $this->describe($item),
                    $columnPath,
                    implode(', ', $allowed),
                ),
                $this->suggest(is_string($item) ? $item : '', $allowed),
            );

            return false;
        }

        return true;
    }

    /**
     * @param  array<mixed>  $input
     * @param  list<string>  $allowed
     */
    private function rejectUnknownKeys(array $input, array $allowed, string $prefix): void
    {
        foreach (array_keys($input) as $key) {
            if (is_string($key) && in_array($key, $allowed, strict: true)) {
                continue;
            }

            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            $this->error(
                $path,
                ValidationCode::UnknownKey,
                sprintf('Unexpected key [%s]. Permitted keys: %s.', (string) $key, implode(', ', $allowed)),
                $this->suggest((string) $key, $allowed),
            );
        }
    }

    /**
     * @param  list<string>  $candidates
     */
    private function suggest(string $given, array $candidates): ?string
    {
        if ($given === '' || $candidates === []) {
            return null;
        }

        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($candidates as $candidate) {
            $distance = levenshtein(strtolower($given), strtolower($candidate));

            if ($distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        // Only suggest a genuine near-miss; an unrelated name is noise that
        // sends an agent down the wrong path.
        return $bestDistance <= (int) ceil(strlen($given) / 2) ? $best : null;
    }

    private function isScalar(mixed $value): bool
    {
        return is_string($value) || is_int($value) || is_float($value) || is_bool($value);
    }

    private function describe(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            is_array($value) => 'array',
            default => 'null',
        };
    }

    private function error(string $path, ValidationCode $code, string $message, ?string $didYouMean = null): void
    {
        $this->errors[] = new ValidationError($path, $code, $message, $didYouMean);
    }
}
