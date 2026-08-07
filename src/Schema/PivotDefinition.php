<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Schema;

use JTMcC\AiQueryBuilder\Schema\Concerns\DeclaresColumns;

/**
 * Columns on the intermediate table of a many-to-many relation.
 *
 * Reached under a reserved `pivot` segment — `tags.pivot.assigned_at` — rather
 * than folded in beside the related table's own columns. One extra segment buys
 * the guarantee that a path names exactly one thing: without it, a pivot
 * `name` and a related `name` would compete for `tags.name`, and which one an
 * agent got would depend on declaration order.
 *
 * A pivot holds no relations. Nothing is traversable through it, because the
 * table it stands for is an implementation detail of the link and not a
 * resource in its own right.
 *
 * Types are not inferred here. The pivot has no Eloquent model to read casts
 * from unless the relation declares a custom pivot class, so a pivot column
 * that needs a type — a date, for `within` — should declare it with `typed()`.
 */
final class PivotDefinition
{
    use DeclaresColumns;

    /**
     * The path segment a pivot is addressed under.
     *
     * Reserved as a relation name, so a path ending in it is never ambiguous.
     */
    public const string SEGMENT = 'pivot';
}
