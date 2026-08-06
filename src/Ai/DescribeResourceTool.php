<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Ai;

use Illuminate\Container\Container as ContainerInstance;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use JTMcC\AiQueryBuilder\Contract\SchemaContract;
use JTMcC\AiQueryBuilder\Schema\SchemaRegistry;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Hands an agent one resource's data dictionary, on request.
 *
 * A QueryDataTool carries its dictionary in its own description, which is
 * resent on every step of the agent loop whether or not that resource is ever
 * queried. Registering several is several dictionaries on every step, including
 * the turn where the user says hello.
 *
 * This tool carries only the resource names and fetches the dictionary for the
 * one the model asks about. It pairs with QueryResourcesTool, whose plan schema
 * does not grow with the contract: together they trade a standing per-resource
 * cost for one round-trip on the first query of a conversation.
 *
 * The resource list is explicit and is never read from the registry, because
 * which resources an agent may touch is the developer's decision.
 */
final class DescribeResourceTool implements Tool
{
    /**
     * @param  list<string>  $resources
     */
    public function __construct(
        private readonly array $resources,
        private readonly ?Authenticatable $user = null,
        private ?SchemaRegistry $registry = null,
    ) {}

    public function name(): string
    {
        return 'describe_query_resource';
    }

    /**
     * The catalogue: what can be queried, one line each.
     */
    public function description(): string
    {
        $lines = [
            'Describes a queryable resource: its columns, what each one supports, and its limits.',
            'Call this before querying a resource you have not been shown.',
            '',
            'Resources:',
        ];

        foreach ($this->resources as $resource) {
            $description = $this->registry()->get($resource)->description();

            $lines[] = '- '.$resource.($description === null ? '' : ' — '.$description);
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()
                ->enum($this->resources)
                ->description('The resource to describe.')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $resource = $request->toArray()['resource'] ?? null;

        // The enum steers decoding rather than enforcing anything, so the list
        // is checked here too. A resource outside it is unknown to this tool
        // even when it is registered for some other agent.
        if (! is_string($resource) || ! in_array($resource, $this->resources, true)) {
            return (string) json_encode([
                'error' => 'unknown_resource',
                'message' => 'No such resource.',
                'resources' => $this->resources,
            ]);
        }

        return SchemaContract::for($this->registry()->get($resource), $this->user)->toPrompt();
    }

    private function registry(): SchemaRegistry
    {
        return $this->registry ??= $this->container()->make(SchemaRegistry::class);
    }

    private function container(): Container
    {
        return ContainerInstance::getInstance();
    }
}
