<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Compilation;

use JTMcC\AiQueryBuilder\Schema\ColumnDefinition;
use JTMcC\AiQueryBuilder\Schema\Enums\DateBucket;

/**
 * What the compiler learned while joining, carried through the rest of compilation.
 */
final readonly class CompilationContext
{
    /**
     * @param  array<string, string>  $tables  Relation path to table name, keyed '' for the root.
     * @param  array<string, true>  $toMany  Relation paths whose join multiplies parent rows.
     * @param  array<string, DateBucket>  $buckets  Column path to the granularity it is grouped at.
     */
    public function __construct(
        public array $tables,
        public array $toMany,
        public array $buckets,
        public string $driver,
    ) {}

    /**
     * Build a qualified identifier from the schema, never from the plan's strings.
     *
     * The path's last segment is the name exposed to the agent; the real column
     * name comes from the definition, so an alias can never reach SQL.
     */
    public function qualify(string $path, ColumnDefinition $column): string
    {
        return $this->tables[self::relationPathOf($path)].'.'.$column->name();
    }

    /**
     * The relation path a column sits on. `total` is the root, `lines.quantity` is `lines`.
     */
    public static function relationPathOf(string $path): string
    {
        $segments = explode('.', $path);
        array_pop($segments);

        return implode('.', $segments);
    }
}
