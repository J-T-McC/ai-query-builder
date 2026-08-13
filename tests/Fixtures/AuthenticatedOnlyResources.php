<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use JTMcC\AiQueryBuilder\Mcp\Contracts\ResolvesExposedResources;

/**
 * Guests see the catalogue; invoices appear only once authenticated.
 */
final class AuthenticatedOnlyResources implements ResolvesExposedResources
{
    /**
     * @return list<string>
     */
    public function resources(?Authenticatable $user): array
    {
        return $user === null ? ['products'] : ['invoices', 'products'];
    }
}
