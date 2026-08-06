<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Schema\Enums;

/**
 * The complete set of filter operators the package will ever compile.
 *
 * A plan referencing anything outside this enum is rejected before compilation,
 * so this list is the outer bound of what an AI agent can express.
 */
enum Operator: string
{
    case Equals = '=';
    case NotEquals = '!=';
    case GreaterThan = '>';
    case GreaterThanOrEqual = '>=';
    case LessThan = '<';
    case LessThanOrEqual = '<=';
    case Between = 'between';

    /**
     * A named date range the package resolves, rather than one the agent
     * computes. Derived from `between` on a date column rather than declared,
     * because it grants no access that `between` did not already grant.
     */
    case Within = 'within';

    case In = 'in';
    case NotIn = 'not_in';
    case Like = 'like';
    case IsNull = 'is_null';
    case IsNotNull = 'is_not_null';
}
