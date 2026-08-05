<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Compilation;

use JTMcC\AiQueryBuilder\Exceptions\CompilationException;
use JTMcC\AiQueryBuilder\Schema\Enums\DateBucket;

/**
 * Truncates a date column to a granularity, per database driver.
 *
 * The column is always a qualified identifier built from the schema, never a
 * value that came from an agent, so it is safe to interpolate here.
 */
final class DateBucketExpression
{
    public static function for(string $driver, DateBucket $bucket, string $column): string
    {
        return match ($driver) {
            'sqlite' => self::sqlite($bucket, $column),
            'mysql', 'mariadb' => self::mysql($bucket, $column),
            'pgsql' => sprintf("date_trunc('%s', %s)", $bucket->value, $column),
            default => throw CompilationException::unsupportedDateBucket($driver, $bucket->value),
        };
    }

    private static function sqlite(DateBucket $bucket, string $column): string
    {
        return match ($bucket) {
            DateBucket::Day => sprintf("strftime('%%Y-%%m-%%d', %s)", $column),
            DateBucket::Week => sprintf("strftime('%%Y-W%%W', %s)", $column),
            DateBucket::Month => sprintf("strftime('%%Y-%%m', %s)", $column),
            DateBucket::Quarter => sprintf(
                "strftime('%%Y', %s) || '-Q' || ((CAST(strftime('%%m', %s) AS INTEGER) + 2) / 3)",
                $column,
                $column,
            ),
            DateBucket::Year => sprintf("strftime('%%Y', %s)", $column),
        };
    }

    private static function mysql(DateBucket $bucket, string $column): string
    {
        return match ($bucket) {
            DateBucket::Day => sprintf("DATE_FORMAT(%s, '%%Y-%%m-%%d')", $column),
            DateBucket::Week => sprintf("DATE_FORMAT(%s, '%%x-W%%v')", $column),
            DateBucket::Month => sprintf("DATE_FORMAT(%s, '%%Y-%%m')", $column),
            DateBucket::Quarter => sprintf("CONCAT(YEAR(%s), '-Q', QUARTER(%s))", $column, $column),
            DateBucket::Year => sprintf('YEAR(%s)', $column),
        };
    }
}
