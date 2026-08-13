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
use JTMcC\AiQueryBuilder\Contract\SchemaContract;
use JTMcC\AiQueryBuilder\Mcp\ExposedResources;
use JTMcC\AiQueryBuilder\Schema\SchemaRegistry;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Hands an MCP client one resource's data dictionary, on request.
 *
 * The MCP counterpart of Ai\DescribeResourceTool, and the same trade: the
 * standing description carries only resource names and one-line descriptions,
 * and the model fetches the full dictionary for the resource it actually
 * wants — so the catalogue does not grow the client's context with every
 * registered resource.
 *
 * Exposure comes from the constructor (a QueryServer passes its own), or from
 * the `mcp.resources` config key when the tool is registered as a bare
 * class-string. Either source may be a resolver class, so the catalogue —
 * including this tool's own description — is computed per request for the
 * authenticated user.
 */
#[IsReadOnly]
#[IsIdempotent]
final class DescribeResourceTool extends Tool
{
    protected string $name = 'describe_query_resource';

    /**
     * @param  array<int, string>|string|null  $exposes
     */
    public function __construct(
        private readonly array|string|null $exposes = null,
        private ?SchemaRegistry $registry = null,
    ) {}

    /**
     * The catalogue: what can be queried, one line each, for this user.
     */
    public function description(): string
    {
        $lines = [
            'Describes a queryable resource: its columns, what each one supports, and its limits.',
            'Call this before querying a resource you have not been shown.',
            '',
            'Resources:',
        ];

        foreach ($this->resources($this->user()) as $resource) {
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
        $resources = $this->resources($this->user());

        return [
            'resource' => $schema->string()
                ->enum($resources)
                ->description('The resource to describe.')
                ->required(),
        ];
    }

    /**
     * Nothing exposed means the tool has no reason to appear in the catalogue.
     */
    public function shouldRegister(): bool
    {
        return $this->resources($this->user()) !== [];
    }

    public function handle(Request $request): Response
    {
        $resource = $request->get('resource');
        $resources = $this->resources($request->user());

        // The enum steers decoding rather than enforcing anything, so the list
        // is checked here too. A resource outside it is unknown to this tool
        // even when it is registered for some other door.
        if (! is_string($resource) || ! in_array($resource, $resources, true)) {
            return Response::error(
                'Unknown resource. Available resources: '.implode(', ', $resources),
            );
        }

        return Response::text(
            SchemaContract::for($this->registry()->get($resource), $request->user())->toPrompt(),
        );
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

    private function registry(): SchemaRegistry
    {
        return $this->registry ??= $this->container()->make(SchemaRegistry::class);
    }

    private function container(): Container
    {
        return ContainerInstance::getInstance();
    }
}
