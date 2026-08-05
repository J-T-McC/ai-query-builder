<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Plan\Enums;

/**
 * How the conditions inside a filter group combine.
 */
enum LogicalOperator: string
{
    case And = 'and';
    case Or = 'or';
}
