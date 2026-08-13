<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Mcp\Tools;

use Illuminate\Auth\AuthManager;
use Illuminate\Container\Container as ContainerInstance;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use JTMcC\AiQueryBuilder\Ai\PlanSchemaDetail;
use JTMcC\AiQueryBuilder\Ai\PlanToolSchema;
use JTMcC\AiQueryBuilder\Contract\SchemaContract;
use JTMcC\AiQueryBuilder\Exceptions\InvalidQueryPlanException;
use JTMcC\AiQueryBuilder\Execution\QueryRunner;
use JTMcC\AiQueryBuilder\Mcp\ExposedResources;
use JTMcC\AiQueryBuilder\Schema\SchemaRegistry;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Runs query plans for MCP clients, across the exposed resources.
 *
 * The MCP counterpart of Ai\QueryResourcesTool: one tool, a generic plan
 * schema that does not grow with any resource's contract, and the column
 * names left to describe_query_resource's dictionary.
 *
 * The exposed resource list is the boundary for this door, and it is checked
 * before the runner sees a plan. The security boundary it sits in front of is
 * unchanged: schema authorization and the validation gate run on every plan,
 * as the acting user, no matter what this list says.
 */
#[IsReadOnly]
#[IsIdempotent]
final class QueryResourcesTool extends Tool
{
    protected string $name = 'query_data';

    /** @var array<string, SchemaContract> */
    private array $contracts = [];

    /**
     * @param  array<int, string>|string|null  $exposes
     */
    public function __construct(
        private readonly array|string|null $exposes = null,
        private ?SchemaRegistry $registry = null,
        private ?QueryRunner $runner = null,
        private readonly int $filterDepth = PlanToolSchema::DEFAULT_FILTER_DEPTH,
    ) {}

    public function description(): string
    {
        return implode("\n", [
            'Queries one of the available resources and returns rows.',
            'Column names come from describe_query_resource. Reference them exactly; anything else is rejected.',
            '',
            'Resources: '.implode(', ', $this->resources($this->user())),
        ]);
    }

    /**
     * One schema for every resource, and it does not grow with any of them.
     *
     * The shape is the union across resources: a clause only one of them can
     * use must still be expressible, and the validator holds each plan to its
     * own resource's rules regardless.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $user = $this->user();
        $resources = $this->resources($user);

        $properties = [];
        $maxRows = 0;

        foreach ($resources as $resource) {
            $contract = $this->contract($resource, $user);
            $maxRows = max($maxRows, $contract->limits()->max);

            $properties += (new PlanToolSchema($contract, $this->filterDepth, PlanSchemaDetail::Generic))
                ->build($schema);
        }

        return [
            'resource' => $schema->string()
                ->enum($resources)
                ->description('Which resource to query.')
                ->required(),
            ...$properties,
            'limit' => $schema->integer()
                ->min(1)
                ->max($maxRows)
                ->description('Maximum rows. Each resource has its own default and its own cap.'),
        ];
    }

    /**
     * Nothing exposed means the tool has no reason to appear in the catalogue.
     */
    public function shouldRegister(): bool
    {
        return $this->resources($this->user()) !== [];
    }

    /**
     * Run the plan the model produced, as the authenticated user.
     *
     * A rejection is returned as normal content rather than an MCP error, so
     * the model treats it as a correctable outcome: fix the listed paths and
     * try again. Response::error() is reserved for plans this tool will never
     * run, whatever the correction.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $plan = $request->all();
        $resource = $plan['resource'] ?? null;
        $resources = $this->resources($request->user());

        if (! is_string($resource) || ! in_array($resource, $resources, true)) {
            return Response::error(
                'That resource is not available to this tool. Available resources: '.implode(', ', $resources),
            );
        }

        try {
            return Response::structured(
                $this->runner()->as($request->user())->run($plan)->toArray(),
            );
        } catch (InvalidQueryPlanException $exception) {
            return Response::json([
                'error' => 'invalid_query_plan',
                'message' => 'The plan was rejected. Correct the listed paths and try again.',
                'errors' => $exception->toArray(),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function resources(?Authenticatable $user): array
    {
        return ExposedResources::resolve($this->exposes(), $user);
    }

    /**
     * @return array<int, string>|string
     */
    private function exposes(): array|string
    {
        if ($this->exposes !== null) {
            return $this->exposes;
        }

        /** @var array<int, string>|string */
        return $this->container()->make(Repository::class)->get('ai-query-builder.mcp.resources', []);
    }

    /**
     * The same user Request::user() resolves, for the request-less metadata
     * paths: tools/list renders the description and schema through the auth
     * middleware but without a tool Request.
     */
    private function user(): ?Authenticatable
    {
        /** @var AuthManager $auth */
        $auth = $this->container()->make('auth');

        return call_user_func($auth->userResolver());
    }

    private function contract(string $resource, ?Authenticatable $user): SchemaContract
    {
        return $this->contracts[$resource] ??= SchemaContract::for(
            $this->registry()->get($resource),
            $user,
        );
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
