<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Schema\Enums;

use DateTimeImmutable;

/**
 * The kind of value a column holds, and therefore the kind of value a filter
 * against it may carry.
 *
 * This exists because a filter value that is the wrong kind does not fail — it
 * compiles, binds and runs, and the comparison silently means something other
 * than what was asked. A model that writes "now-30d" against a date column
 * expects it to be interpreted; MySQL casts it to a zero date and matches every
 * row, SQLite compares it as a string and matches none. Both answer the wrong
 * question with a straight face, which is worse than an error.
 *
 * Nothing here evaluates anything. A relative expression is rejected rather
 * than resolved: the package does not decide what "now" means on a model's
 * behalf, because a value the agent did not choose is a value nobody can audit.
 */
enum ColumnType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Number = 'number';
    case Boolean = 'boolean';
    case Date = 'date';
    case Datetime = 'datetime';

    /**
     * Formats accepted for a date or date-time value.
     *
     * Deliberately not strtotime: it parses "now" and "-30 days" happily, and
     * accepting those would bind the literal string to SQL and reproduce the
     * exact bug this enum exists to stop, on a value that passed validation.
     *
     * @var list<string>
     */
    private const array TEMPORAL_FORMATS = [
        'Y-m-d',
        'Y-m-d H:i',
        'Y-m-d H:i:s',
        'Y-m-d\TH:i',
        'Y-m-d\TH:i:s',
        'Y-m-d\TH:i:sP',
        'Y-m-d\TH:i:s.uP',
    ];

    /**
     * Whether a filter value is usable against a column of this type.
     *
     * Numeric strings pass for numeric columns and vice versa: the database
     * compares them correctly, so rejecting them would cost a round trip and
     * buy nothing. Only values whose comparison would be meaningless are
     * refused.
     */
    public function accepts(mixed $value): bool
    {
        return match ($this) {
            // A label, not a constraint. Any scalar compares as text, so
            // nothing here is confidently wrong and nothing is rejected.
            self::String => true,
            self::Integer => is_int($value)
                || (is_float($value) && $value === floor($value))
                || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
            self::Number => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            self::Boolean => is_bool($value)
                || $value === 0
                || $value === 1
                || (is_string($value) && in_array(strtolower($value), ['0', '1', 'true', 'false'], strict: true)),
            self::Date, self::Datetime => is_string($value) && $this->isTemporal($value),
        };
    }

    /**
     * What to tell a model that got the value wrong.
     */
    public function hint(): string
    {
        return match ($this) {
            self::String => 'Give a text value.',
            self::Integer => 'Give a whole number.',
            self::Number => 'Give a number.',
            self::Boolean => 'Give true or false.',
            self::Date => 'Give an absolute date as YYYY-MM-DD. Relative expressions are not evaluated.',
            self::Datetime => 'Give an absolute date-time as YYYY-MM-DD HH:MM:SS, or YYYY-MM-DD for midnight. Relative expressions are not evaluated.',
        };
    }

    /**
     * Whether a column of this type constrains what a filter value may be.
     *
     * A string column does not, so saying so in the contract would cost tokens
     * on every step to tell a model something it cannot act on.
     */
    public function constrainsValues(): bool
    {
        return $this !== self::String;
    }

    /**
     * Whether the string is a complete, unambiguous point in time.
     *
     * The date must also exist: createFromFormat rolls 2026-02-30 forward to
     * March and reports it as a warning rather than a failure, so the warning
     * count is the part that matters.
     */
    private function isTemporal(string $value): bool
    {
        foreach (self::TEMPORAL_FORMATS as $format) {
            if (DateTimeImmutable::createFromFormat($format, $value) === false) {
                continue;
            }

            if (DateTimeImmutable::getLastErrors() === false) {
                return true;
            }
        }

        return false;
    }
}
