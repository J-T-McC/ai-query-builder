<?php

declare(strict_types=1);

namespace AiQueryBuilder\AiQueryBuilder\Schema\Enums;

/**
 * The complete set of aggregate functions the package will ever compile.
 */
enum Aggregate: string
{
    case Sum = 'sum';
    case Avg = 'avg';
    case Min = 'min';
    case Max = 'max';
    case Count = 'count';
    case CountDistinct = 'count_distinct';
}
