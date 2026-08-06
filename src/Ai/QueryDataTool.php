<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Ai;

use Illuminate\Container\Container as ContainerInstance;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use JTMcC\AiQueryBuilder\Contract\SchemaContract;
use JTMcC\AiQueryBuilder\Exceptions\InvalidQueryPlanException;
use JTMcC\AiQueryBuilder\Execution\QueryRunner;
use JTMcC\AiQueryBuilder\Schema\SchemaRegistry;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Exposes one resource to a Laravel AI SDK agent.
 *
 * Register it on an agent you already have:
 *
 *     public function tools(): iterable
 *     {
 *         return [new QueryDataTool('invoices', auth()->user())];
 *     }
 *
 * Requires laravel/ai, which is a suggested dependency rather than a required
 * one — the package works without any AI layer at all.
 *
 * The tool is constructed by the host application rather than the container, so
 * its collaborators resolve lazily and can be injected in tests.
 */
final class QueryDataTool implements Tool
{
    private ?SchemaContract $contract = null;

    public function __construct(
        private readonly string $resource,
        private readonly ?Authenticatable $user = null,
        private ?SchemaRegistry $registry = null,
        private ?QueryRunner $runner = null,
        private readonly int $filterDepth = PlanToolSchema::DEFAULT_FILTER_DEPTH,
        private readonly PlanSchemaDetail $detail = PlanSchemaDetail::Enumerated,
    ) {}

    /**
     * The name the model calls this tool by.
     *
     * Without one, Laravel\Ai\Tools\ToolNameResolver falls back to the class
     * basename and every instance is called QueryDataTool — so two resources on
     * one agent produce duplicate tool names, which providers reject outright.
     */
    public function name(): string
    {
        return 'query_'.$this->resource;
    }

    /**
     * The data dictionary, scoped to this user. Columns they cannot see are absent.
     */
    public function description(): string
    {
        return $this->contract()->toPrompt();
    }

    /**
     * Each inlined filter level repeats the column and operator enums, so
     * `filterDepth` is close to a linear multiplier on the largest part of the
     * schema. Lowering it bounds only what the model is shown: the validator
     * still allows whatever `maxFilterDepth` allows.
     *
     * `detail` trades the same schema's enums for the dictionary's prose, which
     * stops it growing with the contract at the cost of weaker steering.
     *
     * Measure the difference with `ai-query:describe {resource} --cost`.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return (new PlanToolSchema($this->contract(), $this->filterDepth, $this->detail))->build($schema);
    }

    /**
     * Run the plan the model produced, against this tool's own resource.
     *
     * A rejection is returned to the model rather than thrown, so it can correct
     * the plan. That makes retries the agent loop's decision: cap them with
     * #[MaxSteps(1)] if a single attempt is what you want.
     */
    public function handle(Request $request): string
    {
        $plan = $request->toArray();

        // Set, never default. The schema pins the resource to one value, but a
        // schema constrains decoding rather than enforcing anything. A model
        // naming another registered resource would otherwise redirect the tool
        // away from the one it was built for.
        $plan['resource'] = $this->resource;

        try {
            return $this->runner()->as($this->user)->run($plan)->toJson();
        } catch (InvalidQueryPlanException $exception) {
            return (string) json_encode([
                'error' => 'invalid_query_plan',
                'message' => 'The plan was rejected. Correct the listed paths and try again.',
                'errors' => $exception->toArray(),
            ]);
        }
    }

    /**
     * Built once per instance: the acting user is fixed for its lifetime, and
     * rebuilding re-runs every `visibleWhen` check on a hot path.
     */
    private function contract(): SchemaContract
    {
        return $this->contract ??= SchemaContract::for($this->registry()->get($this->resource), $this->user);
    }

    private function registry(): SchemaRegistry
    {
        return $this->registry ??= $this->container()->make(SchemaRegistry::class);
    }

    private function runner(): QueryRunner
    {
        return $this->runner ??= $this->container()->make(QueryRunner::class);
    }

    private function container(): Container
    {
        return ContainerInstance::getInstance();
    }
}
