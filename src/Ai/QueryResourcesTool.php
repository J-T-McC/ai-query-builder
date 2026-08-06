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
use JTMcC\AiQueryBuilder\Exceptions\SchemaDefinitionException;
use JTMcC\AiQueryBuilder\Execution\QueryRunner;
use JTMcC\AiQueryBuilder\Schema\SchemaRegistry;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Exposes several resources to an agent through one tool.
 *
 * Only coherent on top of a generic plan schema. Enumerating every column of
 * every resource would emit the union of them all and cost more than a tool per
 * resource, so this always builds its schema with PlanSchemaDetail::Generic and
 * leaves the column names to DescribeResourceTool's dictionary.
 *
 * The resource list is the boundary. With one tool per resource, which
 * resources an agent may touch is expressed by which tools were registered on
 * it; here that is this array, so a plan naming anything outside it is refused
 * before the runner sees it.
 */
final class QueryResourcesTool implements Tool
{
    /** @var array<string, SchemaContract> */
    private array $contracts = [];

    /**
     * @param  list<string>  $resources
     */
    public function __construct(
        private readonly array $resources,
        private readonly ?Authenticatable $user = null,
        private ?SchemaRegistry $registry = null,
        private ?QueryRunner $runner = null,
        private readonly int $filterDepth = PlanToolSchema::DEFAULT_FILTER_DEPTH,
    ) {
        if ($resources === []) {
            throw SchemaDefinitionException::emptyResourceList(self::class);
        }
    }

    public function name(): string
    {
        return 'query_data';
    }

    public function description(): string
    {
        return implode("\n", [
            'Queries one of the available resources and returns rows.',
            'Column names come from describe_query_resource. Reference them exactly; anything else is rejected.',
            '',
            'Resources: '.implode(', ', $this->resources),
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
        $properties = [];
        $maxRows = 0;

        foreach ($this->resources as $resource) {
            $contract = $this->contract($resource);
            $maxRows = max($maxRows, $contract->limits()->max);

            $properties += (new PlanToolSchema($contract, $this->filterDepth, PlanSchemaDetail::Generic))
                ->build($schema);
        }

        return [
            'resource' => $schema->string()
                ->enum($this->resources)
                ->description('Which resource to query.')
                ->required(),
            ...$properties,
            'limit' => $schema->integer()
                ->min(1)
                ->max($maxRows)
                ->description('Maximum rows. Each resource has its own default and its own cap.'),
        ];
    }

    public function handle(Request $request): string
    {
        $plan = $request->toArray();
        $resource = $plan['resource'] ?? null;

        if (! is_string($resource) || ! in_array($resource, $this->resources, true)) {
            return (string) json_encode([
                'error' => 'unknown_resource',
                'message' => 'That resource is not available to this tool.',
                'resources' => $this->resources,
            ]);
        }

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

    private function contract(string $resource): SchemaContract
    {
        return $this->contracts[$resource] ??= SchemaContract::for(
            $this->registry()->get($resource),
            $this->user,
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
