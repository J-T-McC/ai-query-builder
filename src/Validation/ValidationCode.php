<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Validation;

/**
 * Machine-readable reasons a query plan was rejected.
 *
 * These codes are part of the package's public contract: they are what an AI
 * layer keys off when deciding whether a rejection is worth retrying, and what
 * QueryPlanRejected reports for failure-rate metrics.
 */
enum ValidationCode: string
{
    case UnknownKey = 'unknown_key';
    case InvalidType = 'invalid_type';
    case UnknownColumn = 'unknown_column';
    case UnknownResource = 'unknown_resource';
    case ColumnNotSelectable = 'column_not_selectable';
    case ColumnNotFilterable = 'column_not_filterable';
    case ColumnNotGroupable = 'column_not_groupable';
    case ColumnNotSortable = 'column_not_sortable';
    case OperatorNotAllowed = 'operator_not_allowed';
    case FunctionNotAllowed = 'function_not_allowed';
    case BucketNotAllowed = 'bucket_not_allowed';
    case ValueNotInEnum = 'value_not_in_enum';
    case ValueTypeMismatch = 'value_type_mismatch';
    case UnknownTimeWindow = 'unknown_time_window';
    case InvalidValueShape = 'invalid_value_shape';
    case DuplicateAlias = 'duplicate_alias';
    case UnknownAlias = 'unknown_alias';
    case EmptySelect = 'empty_select';
    case UngroupedColumn = 'ungrouped_column';
    case RelationDepthExceeded = 'relation_depth_exceeded';
    case FilterTooComplex = 'filter_too_complex';
    case LimitOutOfRange = 'limit_out_of_range';
}
