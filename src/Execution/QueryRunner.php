<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Execution;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use JTMcC\AiQueryBuilder\Compilation\PlanCompiler;
use JTMcC\AiQueryBuilder\Events\QueryPlanExecuted;
use JTMcC\AiQueryBuilder\Events\QueryPlanRejected;
use JTMcC\AiQueryBuilder\Events\QueryPlanValidated;
use JTMcC\AiQueryBuilder\Exceptions\ExecutionException;
use JTMcC\AiQueryBuilder\Exceptions\InvalidQueryPlanException;
use JTMcC\AiQueryBuilder\Plan\QueryPlan;
use JTMcC\AiQueryBuilder\Schema\SchemaRegistry;
use JTMcC\AiQueryBuilder\Validation\PlanValidator;

/**
 * Runs an untrusted plan end to end: validate, compile, execute, report.
 *
 * The fluent setters clone, so a configured runner can be shared without one
 * call leaking settings into the next.
 */
final class QueryRunner
{
    private ?Authenticatable $user = null;

    private ?string $connection;

    private ?int $timeout;

    private int $maxRows;

    private ?string $prompt = null;

    public function __construct(
        private readonly SchemaRegistry $registry,
        private readonly PlanValidator $validator,
        private readonly PlanCompiler $compiler,
        private readonly Dispatcher $events,
        ?string $connection = null,
        ?int $timeout = null,
        int $maxRows = 1000,
    ) {
        $this->connection = $connection;
        $this->timeout = $timeout;
        $this->maxRows = $maxRows;
    }

    /**
     * The user the plan runs as. Drives per-column visibility and every scope.
     */
    public function as(?Authenticatable $user): self
    {
        $clone = clone $this;
        $clone->user = $user;

        return $clone;
    }

    /**
     * Run against a different connection, typically a read-only replica.
     */
    public function connection(?string $connection): self
    {
        $clone = clone $this;
        $clone->connection = $connection;

        return $clone;
    }

    /**
     * Statement timeout in milliseconds. Null runs without one.
     */
    public function timeout(?int $milliseconds): self
    {
        $clone = clone $this;
        $clone->timeout = $milliseconds;

        return $clone;
    }

    public function maxRows(int $rows): self
    {
        $clone = clone $this;
        $clone->maxRows = $rows;

        return $clone;
    }

    /**
     * The natural-language prompt behind this plan, carried into the audit events.
     */
    public function withPrompt(?string $prompt): self
    {
        $clone = clone $this;
        $clone->prompt = $prompt;

        return $clone;
    }

    /**
     * Compile a plan without running it, for approval or logging.
     *
     * @param  array<string, mixed>  $input
     */
    public function explain(array $input): CompiledQuery
    {
        $plan = $this->validate($input);
        $query = $this->build($plan);

        return new CompiledQuery($plan, $query->toSql(), $query->getBindings());
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws InvalidQueryPlanException
     */
    public function run(array $input): ResultSet
    {
        $plan = $this->validate($input);
        $query = $this->build($plan);

        // One row beyond the cap, so a full result can be distinguished from a
        // truncated one without a second count query.
        $limit = min($plan->limit, $this->maxRows);
        $query->limit($limit + 1);

        $connection = $query->getModel()->getConnection();

        $started = microtime(true);
        $rows = $this->withTimeout($connection, static fn (): array => $query->get()->map(
            static fn (Model $row): array => $row->attributesToArray(),
        )->all());
        $duration = (microtime(true) - $started) * 1000;

        $truncated = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $this->events->dispatch(new QueryPlanExecuted(
            plan: $plan,
            sql: $query->toSql(),
            bindings: $query->getBindings(),
            rowCount: count($rows),
            durationMs: $duration,
            truncated: $truncated,
            user: $this->user,
            prompt: $this->prompt,
        ));

        return new ResultSet(
            resource: $plan->resource,
            rows: $rows,
            columns: $this->describeColumns($plan),
            truncated: $truncated,
            durationMs: $duration,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function validate(array $input): QueryPlan
    {
        $resource = $input['resource'] ?? null;

        if (! is_string($resource)) {
            throw ExecutionException::missingResource();
        }

        $schema = $this->registry->get($resource);

        try {
            $plan = $this->validator->validate($input, $schema, $this->user);
        } catch (InvalidQueryPlanException $exception) {
            $this->events->dispatch(new QueryPlanRejected(
                resource: $resource,
                input: $input,
                exception: $exception,
                user: $this->user,
                prompt: $this->prompt,
            ));

            throw $exception;
        }

        $this->events->dispatch(new QueryPlanValidated($plan, $this->user, $this->prompt));

        return $plan;
    }

    /**
     * @return Builder<Model>
     */
    private function build(QueryPlan $plan): Builder
    {
        return $this->compiler->compile(
            $plan,
            $this->registry->get($plan->resource),
            $this->user,
            $this->connection,
        );
    }

    /**
     * Apply a statement timeout for the duration of one query, then restore it.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withTimeout(Connection $connection, callable $callback): mixed
    {
        if ($this->timeout === null) {
            return $callback();
        }

        $driver = $connection->getDriverName();
        $timeout = $this->timeout;

        [$set, $reset] = match ($driver) {
            'pgsql' => ["SET statement_timeout = {$timeout}", 'SET statement_timeout = DEFAULT'],
            'mysql', 'mariadb' => [
                "SET SESSION max_execution_time = {$timeout}",
                'SET SESSION max_execution_time = 0',
            ],
            default => throw ExecutionException::timeoutUnsupported($driver),
        };

        $connection->statement($set);

        try {
            return $callback();
        } finally {
            $connection->statement($reset);
        }
    }

    /**
     * Unit and description metadata for each projected column.
     *
     * @return array<string, array{unit?: string, description?: string}>
     */
    private function describeColumns(QueryPlan $plan): array
    {
        $columns = [];

        foreach ($plan->select as $clause) {
            $meta = [];

            if ($clause->column->unit() !== null) {
                $meta['unit'] = $clause->column->unit();
            }

            if ($clause->column->description() !== null) {
                $meta['description'] = $clause->column->description();
            }

            $columns[$clause->alias] = $meta;
        }

        return $columns;
    }
}
