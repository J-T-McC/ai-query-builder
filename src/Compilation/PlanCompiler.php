<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Compilation;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\JoinClause;
use JTMcC\AiQueryBuilder\Exceptions\CompilationException;
use JTMcC\AiQueryBuilder\Plan\Enums\LogicalOperator;
use JTMcC\AiQueryBuilder\Plan\FilterCondition;
use JTMcC\AiQueryBuilder\Plan\FilterGroup;
use JTMcC\AiQueryBuilder\Plan\QueryPlan;
use JTMcC\AiQueryBuilder\Schema\ColumnDefinition;
use JTMcC\AiQueryBuilder\Schema\Enums\Aggregate;
use JTMcC\AiQueryBuilder\Schema\Enums\Operator;
use JTMcC\AiQueryBuilder\Schema\ResourceSchema;

/**
 * Turns a validated plan into an Eloquent builder.
 *
 * Two guarantees live here and nowhere else:
 *
 * 1. Mandatory scopes are applied before anything from the plan, and the plan's
 *    own filters are wrapped in a single group. Without that nesting, an `or` at
 *    the top of an agent's filter tree would escape the scope entirely.
 * 2. Identifiers are built from ColumnDefinition objects. No string that came
 *    from a plan is ever interpolated into SQL; values are always bound.
 */
final class PlanCompiler
{
    /**
     * @param  string|null  $connection  Overrides the model's connection, so a plan can be
     *                                   pointed at a read-only replica.
     * @return Builder<Model>
     */
    public function compile(
        QueryPlan $plan,
        ResourceSchema $schema,
        ?Authenticatable $user = null,
        ?string $connection = null,
    ): Builder {
        $model = $schema->model() ?? throw CompilationException::missingModel($plan->resource);

        /** @var Builder<Model> $query */
        $query = $connection === null ? $model::query() : $model::on($connection);

        // Mandatory scopes first, at the outermost level of the WHERE clause.
        foreach ($schema->alwaysScopes() as $scope) {
            $scope($query, $user);
        }

        $context = $this->applyJoins($query, $plan, $schema, $user);

        $this->guardAgainstFanOut($plan, $context);

        // The agent's filters go inside their own group. This is the line that
        // stops `tenant_id = 1 AND a OR b` from returning another tenant's rows.
        if ($plan->filters !== null) {
            $filters = $plan->filters;

            $query->where(function (Builder $nested) use ($filters, $context): void {
                $this->applyFilterGroup($nested, $filters, $context);
            });
        }

        $this->applySelect($query, $plan, $context);
        $this->applyGroupBy($query, $plan, $context);
        $this->applyHaving($query, $plan);
        $this->applySort($query, $plan, $context);

        $query->limit(min($plan->limit, $schema->limits()->max));

        return $query;
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyJoins(
        Builder $query,
        QueryPlan $plan,
        ResourceSchema $schema,
        ?Authenticatable $user,
    ): CompilationContext {
        $root = $query->getModel();

        // The root is not aliased, so a mandatory scope on the resource keeps
        // referring to the real table.
        $aliases = ['' => $root->getTable()];
        $models = ['' => $root];
        $toMany = [];

        $paths = $plan->relationPaths();

        // Shallowest first, so a parent is always joined before its children.
        usort($paths, static fn (string $a, string $b): int => substr_count($a, '.') <=> substr_count($b, '.'));

        foreach ($paths as $path) {
            $segments = explode('.', $path);
            $name = (string) array_pop($segments);
            $parent = $models[implode('.', $segments)];

            $relation = $parent->{$name}();

            if (! $relation instanceof Relation) {
                throw CompilationException::unsupportedRelation($path, get_debug_type($relation));
            }

            $parentAlias = $aliases[implode('.', $segments)];
            $alias = self::aliasFor($path);

            // Both sides are built from the alias rather than from the relation's
            // qualified key names, which would name the real table.
            [$first, $second] = match (true) {
                $relation instanceof BelongsTo => [
                    $parentAlias.'.'.$relation->getForeignKeyName(),
                    $alias.'.'.$relation->getOwnerKeyName(),
                ],
                $relation instanceof HasOneOrMany => [
                    $parentAlias.'.'.$relation->getLocalKeyName(),
                    $alias.'.'.$relation->getForeignKeyName(),
                ],
                default => throw CompilationException::unsupportedRelation($path, class_basename($relation)),
            };

            $definition = $schema->findRelation($path);
            $scopes = $definition?->alwaysScopes() ?? [];

            // A join runs none of the related model's global scopes, so the
            // soft-delete condition Eloquent would have added is applied here.
            $deletedAt = $definition?->includesTrashed() === true
                ? null
                : $this->deletedAtColumn($relation->getRelated(), $alias);

            // Left join, so a parent with no related rows is not dropped. The
            // relation's own scopes go on the ON clause rather than the WHERE
            // clause, which would silently make this an inner join.
            $query->leftJoin($relation->getRelated()->getTable()." as {$alias}", function (JoinClause $join) use (
                $first,
                $second,
                $deletedAt,
                $scopes,
                $alias,
                $user,
            ): void {
                $join->on($first, '=', $second);

                if ($deletedAt !== null) {
                    $join->whereNull($deletedAt);
                }

                foreach ($scopes as $scope) {
                    $scope($join, $user, $alias);
                }
            });

            $models[$path] = $relation->getRelated();
            $aliases[$path] = $alias;

            if ($relation instanceof HasOneOrMany && ! $relation instanceof HasOne) {
                $toMany[$path] = true;
            }
        }

        $buckets = [];

        foreach ($plan->groupBy as $clause) {
            if ($clause->bucket !== null) {
                $buckets[$clause->path] = $clause->bucket;
            }
        }

        return new CompilationContext(
            tables: $aliases,
            toMany: $toMany,
            buckets: $buckets,
            driver: $query->getModel()->getConnection()->getDriverName(),
        );
    }

    /**
     * The SQL alias for a joined relation path.
     *
     * Derived from the path, so the same plan always compiles to the same SQL.
     * Every joined table is aliased, including the ones that would not collide,
     * because an alias that appears only sometimes is worse than one that
     * always does: a relation scope would work until the day another path
     * reached the same table.
     */
    public static function aliasFor(string $path): string
    {
        return str_replace('.', '__', $path);
    }

    /**
     * The deleted-at column of a soft-deleting model, qualified by its alias.
     *
     * Detected from the accessor the SoftDeletes trait adds rather than from
     * the trait itself, so a model that renames the column through DELETED_AT
     * is handled without a special case.
     */
    private function deletedAtColumn(Model $model, string $alias): ?string
    {
        return method_exists($model, 'getDeletedAtColumn')
            ? $alias.'.'.$model->getDeletedAtColumn()
            : null;
    }

    /**
     * Refuse to compile an aggregate that a to-many join would inflate.
     *
     * Joining invoices to their lines repeats each invoice once per line, so
     * SUM(invoices.total) counts the same invoice several times. The number that
     * comes back looks entirely plausible, which is what makes it dangerous.
     */
    private function guardAgainstFanOut(QueryPlan $plan, CompilationContext $context): void
    {
        foreach ($plan->select as $clause) {
            if (! $clause->isAggregated()) {
                continue;
            }

            $on = CompilationContext::relationPathOf($clause->path);

            foreach (array_keys($context->toMany) as $join) {
                if ($on === $join || str_starts_with($on, $join.'.')) {
                    continue;
                }

                throw CompilationException::fanOutAggregate($clause->path, $join);
            }
        }
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyFilterGroup(Builder $query, FilterGroup $group, CompilationContext $context): void
    {
        $boolean = $group->operator === LogicalOperator::Or ? 'or' : 'and';

        foreach ($group->conditions as $condition) {
            if ($condition instanceof FilterGroup) {
                $query->where(function (Builder $nested) use ($condition, $context): void {
                    $this->applyFilterGroup($nested, $condition, $context);
                }, null, null, $boolean);

                continue;
            }

            $this->applyCondition($query, $condition, $context, $boolean);
        }
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyCondition(
        Builder $query,
        FilterCondition $condition,
        CompilationContext $context,
        string $boolean,
    ): void {
        $column = $context->qualify($condition->path, $condition->column);

        /** @var list<mixed> $values */
        $values = is_array($condition->value) ? array_values($condition->value) : [];

        match ($condition->operator) {
            // The validator has already turned the named window into the two
            // bounds it stands for, so there is nothing left to interpret here.
            Operator::Between, Operator::Within => $query->whereBetween($column, $values, $boolean),
            Operator::In => $query->whereIn($column, $values, $boolean),
            Operator::NotIn => $query->whereIn($column, $values, $boolean, true),
            Operator::IsNull => $query->whereNull($column, $boolean),
            Operator::IsNotNull => $query->whereNull($column, $boolean, true),
            default => $query->where($column, $condition->operator->value, $condition->value, $boolean),
        };
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applySelect(Builder $query, QueryPlan $plan, CompilationContext $context): void
    {
        $grammar = $query->getQuery()->getGrammar();
        $columns = [];

        foreach ($plan->select as $clause) {
            $columns[] = new SqlFragment(
                $this->expression($clause->path, $clause->column, $clause->function, $context, $grammar)
                .' as '.$grammar->wrap($clause->alias),
            );
        }

        $query->select($columns);
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyGroupBy(Builder $query, QueryPlan $plan, CompilationContext $context): void
    {
        $grammar = $query->getQuery()->getGrammar();

        foreach ($plan->groupBy as $clause) {
            $query->groupBy(
                new SqlFragment($this->expression($clause->path, $clause->column, null, $context, $grammar)),
            );
        }
    }

    /**
     * Having always targets a projected alias, so the grammar wraps a plain
     * identifier and the comparison value is bound by the query builder.
     *
     * @param  Builder<Model>  $query
     */
    private function applyHaving(Builder $query, QueryPlan $plan): void
    {
        foreach ($plan->having as $clause) {
            /** @var list<mixed> $values */
            $values = is_array($clause->value) ? array_values($clause->value) : [];

            match ($clause->operator) {
                // Expressed as two inclusive bounds rather than havingBetween:
                // identical semantics, and both values stay bound.
                Operator::Between => $query
                    ->having($clause->alias, '>=', $values[0] ?? null)
                    ->having($clause->alias, '<=', $values[1] ?? null),
                Operator::IsNull => $query->havingNull($clause->alias),
                Operator::IsNotNull => $query->havingNotNull($clause->alias),
                default => $query->having($clause->alias, $clause->operator->value, $clause->value),
            };
        }
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applySort(Builder $query, QueryPlan $plan, CompilationContext $context): void
    {
        $grammar = $query->getQuery()->getGrammar();

        foreach ($plan->sort as $clause) {
            $target = $clause->column === null
                ? $clause->reference
                : new SqlFragment(
                    $this->expression($clause->reference, $clause->column, null, $context, $grammar),
                );

            $query->orderBy($target, $clause->direction->value);
        }
    }

    /**
     * Build the SQL for one column: its aggregate, its date bucket, or the bare
     * qualified identifier. Every part comes from the schema.
     */
    private function expression(
        string $path,
        ColumnDefinition $column,
        ?Aggregate $function,
        CompilationContext $context,
        Grammar $grammar,
    ): string {
        $wrapped = $grammar->wrap($context->qualify($path, $column));

        if ($function !== null) {
            return $function === Aggregate::CountDistinct
                ? "COUNT(DISTINCT {$wrapped})"
                : strtoupper($function->value)."({$wrapped})";
        }

        if (isset($context->buckets[$path])) {
            return DateBucketExpression::for($context->driver, $context->buckets[$path], $wrapped);
        }

        return $wrapped;
    }
}
