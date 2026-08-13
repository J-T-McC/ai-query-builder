<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Mcp;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use JTMcC\AiQueryBuilder\Exceptions\SchemaDefinitionException;
use JTMcC\AiQueryBuilder\Mcp\Contracts\ResolvesExposedResources;

/**
 * Normalizes an exposure declaration into the resource list for one user.
 *
 * Exposure is declared as either a static list of resource names or a
 * class-string of a ResolvesExposedResources implementation. Both MCP tools
 * accept the raw declaration and normalize it here at request time, because
 * only then is the acting user known.
 */
final class ExposedResources
{
    /**
     * @param  array<int, string>|string  $exposes
     * @return list<string>
     */
    public static function resolve(array|string $exposes, ?Authenticatable $user): array
    {
        if (is_array($exposes)) {
            return array_values($exposes);
        }

        $resolver = Container::getInstance()->make($exposes);

        if (! $resolver instanceof ResolvesExposedResources) {
            throw SchemaDefinitionException::invalidExposureResolver($exposes);
        }

        return $resolver->resources($user);
    }
}
