<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Mcp\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Resolves which resources an MCP server exposes to the current user.
 *
 * Configure a class-string implementing this contract anywhere a static
 * resource list is accepted — the `mcp.resources` config key or a
 * QueryServer's `$exposes` property — and it is resolved from the container
 * and consulted per request. Every MCP request passes through the server's
 * auth middleware first, so two users connected to the same endpoint can be
 * shown different catalogues.
 *
 * Exposure is a catalogue decision, not the security boundary. Schema
 * authorization (`visibleWhen`, `alwaysScope`, the validation gate) runs on
 * every plan regardless of what this resolver returns.
 */
interface ResolvesExposedResources
{
    /**
     * @return list<string>
     */
    public function resources(?Authenticatable $user): array;
}
