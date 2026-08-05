<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Schema\Enums;

/**
 * Granularities a date column may be truncated to when grouping.
 */
enum DateBucket: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Quarter = 'quarter';
    case Year = 'year';
}
