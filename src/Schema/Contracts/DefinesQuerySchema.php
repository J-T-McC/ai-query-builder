<?php

declare(strict_types=1);

namespace AiQueryBuilder\AiQueryBuilder\Schema\Contracts;

use AiQueryBuilder\AiQueryBuilder\Schema\ResourceSchema;

/**
 * Implemented by application classes that expose a resource to AI agents.
 */
interface DefinesQuerySchema
{
    public function define(ResourceSchema $schema): ResourceSchema;
}
