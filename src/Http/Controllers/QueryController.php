<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JTMcC\AiQueryBuilder\Exceptions\ExecutionException;
use JTMcC\AiQueryBuilder\Exceptions\InvalidQueryPlanException;
use JTMcC\AiQueryBuilder\Exceptions\UnknownResourceException;
use JTMcC\AiQueryBuilder\Execution\QueryRunner;

/**
 * Runs a query plan posted as JSON.
 *
 * Off unless enabled in config, because whether an HTTP surface should exist at
 * all — and behind which middleware — is the application's decision.
 *
 * The plan comes from the request body; the resource comes from the URL and
 * overrides any resource in the body, so a route protected for one resource
 * cannot be used to query another.
 *
 * Compiled SQL is never returned. It would disclose table and column names to a
 * client that only needs rows. Use QueryRunner::explain() server-side for that.
 */
final class QueryController
{
    public function __construct(private readonly QueryRunner $runner) {}

    public function __invoke(Request $request, string $resource): JsonResponse
    {
        /** @var array<string, mixed> $plan */
        $plan = $request->json()->all();
        $plan['resource'] = $resource;

        try {
            $result = $this->runner->as($request->user())->run($plan);
        } catch (InvalidQueryPlanException $exception) {
            return new JsonResponse([
                'message' => 'The query plan was rejected.',
                'errors' => $exception->toArray(),
            ], 422);
        } catch (UnknownResourceException) {
            // Deliberately not echoing the registered resources: an unauthenticated
            // probe should not be able to enumerate them.
            return new JsonResponse(['message' => 'Unknown resource.'], 404);
        } catch (ExecutionException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 400);
        }

        return new JsonResponse($result->toArray());
    }
}
