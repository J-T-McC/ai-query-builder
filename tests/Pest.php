<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use JTMcC\AiQueryBuilder\Compilation\PlanCompiler;
use JTMcC\AiQueryBuilder\Exceptions\InvalidQueryPlanException;
use JTMcC\AiQueryBuilder\Plan\QueryPlan;
use JTMcC\AiQueryBuilder\Schema\ResourceSchema;
use JTMcC\AiQueryBuilder\Tests\Fixtures\InvoiceSchema;
use JTMcC\AiQueryBuilder\Tests\TestCase;
use JTMcC\AiQueryBuilder\Validation\PlanValidator;
use JTMcC\AiQueryBuilder\Validation\ValidationError;

uses(TestCase::class)->in(__DIR__);

/**
 * Validate a plan against the invoice fixture, expecting it to pass.
 *
 * @param  array<string, mixed>  $input
 */
function validatePlan(array $input, ?Authenticatable $user = null): QueryPlan
{
    return (new PlanValidator)->validate($input, InvoiceSchema::make(), $user);
}

/**
 * Validate and compile a plan, returning the Eloquent builder.
 *
 * @param  array<string, mixed>  $input
 * @return Builder<Model>
 */
function compilePlan(array $input, ?ResourceSchema $schema = null, ?Authenticatable $user = null): Builder
{
    $schema ??= InvoiceSchema::make();

    return (new PlanCompiler)->compile(
        (new PlanValidator)->validate($input, $schema, $user),
        $schema,
        $user,
    );
}

/**
 * Validate a plan expecting rejection, and return the errors it was rejected with.
 *
 * @param  array<string, mixed>  $input
 * @return array<string, ValidationError> Keyed by error path, first error wins.
 */
function rejectPlan(array $input, ?Authenticatable $user = null): array
{
    try {
        validatePlan($input, $user);
    } catch (InvalidQueryPlanException $exception) {
        $keyed = [];

        foreach ($exception->errors() as $error) {
            $keyed[$error->path] ??= $error;
        }

        return $keyed;
    }

    throw new RuntimeException('The plan was expected to be rejected but passed validation.');
}
