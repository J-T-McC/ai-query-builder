<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Plan;

use Carbon\CarbonImmutable;
use JTMcC\AiQueryBuilder\Schema\Enums\ColumnType;

/**
 * A date range an agent can name instead of computing.
 *
 * The grammar is closed on purpose. A general parser looks like the friendlier
 * choice and is measurably not: strtotime resolves "now-30d" — the string that
 * caused the incident this exists to prevent — to thirty *hours* in the future,
 * reads "01/02/2026" as January, and turns "last month" into a point in time a
 * month back rather than the month itself, while rejecting "last 30 days",
 * "this quarter" and "year to date" outright. Its accept/reject boundary is
 * close to the opposite of the useful one. Everything it wrongly rejects is
 * named here; everything it wrongly accepts is refused.
 *
 * The window stays in the plan and is resolved on each validation, so a stored
 * plan keeps meaning what it said. Resolving it into the plan would freeze it
 * to the day it was written, and replaying that later answers a different
 * question than the one asked while looking like it answered the right one —
 * the same failure this whole mechanism exists to close.
 */
final readonly class TimeWindow
{
    /**
     * Windows aligned to calendar boundaries.
     *
     * @var list<string>
     */
    private const array NAMED = [
        'today',
        'yesterday',
        'this_week',
        'last_week',
        'this_month',
        'last_month',
        'this_quarter',
        'last_quarter',
        'this_year',
        'last_year',
        'month_to_date',
        'quarter_to_date',
        'year_to_date',
    ];

    /**
     * Rolling windows of an arbitrary size, ending now.
     */
    private const string ROLLING = '/^last_(\d{1,4})_(seconds|minutes|hours|days|weeks|months|years)$/';

    /**
     * Windows shorter than a day, which only mean anything on a column that
     * stores a time.
     *
     * @var list<string>
     */
    private const array SUB_DAY = ['seconds', 'minutes', 'hours'];

    /**
     * What an agent most likely meant by a relative expression.
     */
    private const string INTENT = '/(\d{1,4})\s*-?\s*([a-z]+)/i';

    private function __construct(public string $expression) {}

    public static function tryFrom(string $value): ?self
    {
        $named = in_array($value, self::NAMED, strict: true);

        return $named || preg_match(self::ROLLING, $value) === 1 ? new self($value) : null;
    }

    /**
     * Every window that can be named, for the contract handed to an agent.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return self::NAMED;
    }

    /**
     * Read a relative expression as the window it was reaching for.
     *
     * "now-30d" and "-30 days" both become last_30_days, which turns the
     * rejection into a correction the agent can apply in one step rather than
     * a rule it has to reason from.
     */
    public static function suggestFor(string $value): ?string
    {
        if (preg_match(self::INTENT, $value, $matches) !== 1) {
            return null;
        }

        // A bare "m" is read as months. It is genuinely ambiguous against
        // minutes, and a suggestion is a starting point the agent can override,
        // not a decision made on its behalf.
        $unit = match (strtolower($matches[2])) {
            's', 'sec', 'secs', 'second', 'seconds' => 'seconds',
            'min', 'mins', 'minute', 'minutes' => 'minutes',
            'h', 'hr', 'hrs', 'hour', 'hours' => 'hours',
            'd', 'day', 'days' => 'days',
            'w', 'wk', 'wks', 'week', 'weeks' => 'weeks',
            'm', 'mo', 'mon', 'month', 'months' => 'months',
            'y', 'yr', 'yrs', 'year', 'years' => 'years',
            default => null,
        };

        return $unit === null ? null : 'last_'.(int) $matches[1].'_'.$unit;
    }

    /**
     * Whether this window means anything against that kind of column.
     *
     * A window shorter than a day, resolved against a date column, would
     * quietly collapse to "today" — the exact class of silently-wrong answer
     * this grammar exists to prevent.
     */
    public function appliesTo(ColumnType $type): bool
    {
        if ($type !== ColumnType::Date) {
            return true;
        }

        return preg_match(self::ROLLING, $this->expression, $matches) !== 1
            || ! in_array($matches[2], self::SUB_DAY, strict: true);
    }

    /**
     * The inclusive bounds of this window, formatted for the column it filters.
     *
     * A date column is bound as a bare date. Giving it a time would exclude the
     * boundary day everywhere a date is stored as text, because '2026-07-07'
     * sorts before '2026-07-07 00:00:00'.
     *
     * @return array{0: string, 1: string}
     */
    public function resolve(ColumnType $type, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        [$start, $end] = $this->bounds($now);

        $format = $type === ColumnType::Date ? 'Y-m-d' : 'Y-m-d H:i:s';

        return [$start->format($format), $end->format($format)];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function bounds(CarbonImmutable $now): array
    {
        if (preg_match(self::ROLLING, $this->expression, $matches) === 1) {
            return [$this->rollBack($now, (int) $matches[1], $matches[2]), $now];
        }

        // Previous periods step back a day from the current period's start
        // rather than subtracting a month or a quarter, which would have to
        // answer what one month before the 31st is.
        return match ($this->expression) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'yesterday' => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay()],
            'this_week' => [$now->startOfWeek(), $now->endOfWeek()],
            'last_week' => [$now->startOfWeek()->subDay()->startOfWeek(), $now->startOfWeek()->subDay()->endOfWeek()],
            'this_month' => [$now->startOfMonth(), $now->endOfMonth()],
            'last_month' => [$now->startOfMonth()->subDay()->startOfMonth(), $now->startOfMonth()->subDay()->endOfMonth()],
            'this_quarter' => [$now->startOfQuarter(), $now->endOfQuarter()],
            'last_quarter' => [
                $now->startOfQuarter()->subDay()->startOfQuarter(),
                $now->startOfQuarter()->subDay()->endOfQuarter(),
            ],
            'this_year' => [$now->startOfYear(), $now->endOfYear()],
            'last_year' => [$now->startOfYear()->subDay()->startOfYear(), $now->startOfYear()->subDay()->endOfYear()],
            'month_to_date' => [$now->startOfMonth(), $now],
            'quarter_to_date' => [$now->startOfQuarter(), $now],
            default => [$now->startOfYear(), $now],
        };
    }

    private function rollBack(CarbonImmutable $now, int $size, string $unit): CarbonImmutable
    {
        return match ($unit) {
            'seconds' => $now->subSeconds($size),
            'minutes' => $now->subMinutes($size),
            'hours' => $now->subHours($size),
            'days' => $now->subDays($size),
            'weeks' => $now->subWeeks($size),
            'months' => $now->subMonthsNoOverflow($size),
            default => $now->subYearsNoOverflow($size),
        };
    }
}
