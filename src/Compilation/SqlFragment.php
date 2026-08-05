<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Compilation;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * A SQL fragment the compiler generated.
 *
 * Laravel's own `Expression` is typed `literal-string`, which a query compiler
 * cannot satisfy: aggregate and date-bucket expressions are assembled at
 * runtime. This type is the single, auditable place where that happens.
 *
 * The invariant that makes it safe: a fragment is only ever built from
 * identifiers the developer declared in a ResourceSchema, wrapped by the
 * connection's grammar. No value from a query plan is ever interpolated —
 * values are bound as parameters by the query builder.
 */
final readonly class SqlFragment implements Expression
{
    public function __construct(private string $sql) {}

    public function getValue(Grammar $grammar): string
    {
        return $this->sql;
    }
}
