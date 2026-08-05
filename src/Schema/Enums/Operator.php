<?php

declare(strict_types=1);

namespace AiQueryBuilder\AiQueryBuilder\Schema\Enums;

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
    case In = 'in';
    case NotIn = 'not_in';
    case Like = 'like';
    case IsNull = 'is_null';
    case IsNotNull = 'is_not_null';
}
