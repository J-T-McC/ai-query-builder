<?php

declare(strict_types=1);

use Carbon\Carbon;
use JTMcC\AiQueryBuilder\Plan\TimeWindow;
use JTMcC\AiQueryBuilder\Schema\Enums\ColumnType;

beforeEach(fn () => Carbon::setTestNow('2026-08-06 14:30:00'));
afterEach(fn () => Carbon::setTestNow());

/**
 * @return array{0: string, 1: string}
 */
function window(string $expression, ColumnType $type = ColumnType::Datetime): array
{
    $window = TimeWindow::tryFrom($expression);

    expect($window)->not->toBeNull();

    return $window->resolve($type);
}

describe('calendar windows', function () {
    it('resolves a named window to its calendar bounds', function (string $name, string $start, string $end) {
        expect(window($name))->toBe([$start, $end]);
    })->with([
        ['today', '2026-08-06 00:00:00', '2026-08-06 23:59:59'],
        ['yesterday', '2026-08-05 00:00:00', '2026-08-05 23:59:59'],
        ['this_month', '2026-08-01 00:00:00', '2026-08-31 23:59:59'],
        // The whole of July, not a point in time a month back — which is what a
        // general parser reads "last month" as.
        ['last_month', '2026-07-01 00:00:00', '2026-07-31 23:59:59'],
        ['this_quarter', '2026-07-01 00:00:00', '2026-09-30 23:59:59'],
        ['last_quarter', '2026-04-01 00:00:00', '2026-06-30 23:59:59'],
        ['this_year', '2026-01-01 00:00:00', '2026-12-31 23:59:59'],
        ['last_year', '2025-01-01 00:00:00', '2025-12-31 23:59:59'],
    ]);

    it('ends a to-date window at this instant, not at the end of the period', function () {
        expect(window('month_to_date'))->toBe(['2026-08-01 00:00:00', '2026-08-06 14:30:00'])
            ->and(window('year_to_date'))->toBe(['2026-01-01 00:00:00', '2026-08-06 14:30:00']);
    });

    it('steps back a period without asking what one month before the 31st is', function () {
        Carbon::setTestNow('2026-03-31 09:00:00');

        // strtotime('-1 month') from here lands on March 3rd. Stepping back a
        // day from the start of March cannot.
        expect(window('last_month'))->toBe(['2026-02-01 00:00:00', '2026-02-28 23:59:59']);
    });

    it('handles a leap year the same way', function () {
        Carbon::setTestNow('2028-03-15 09:00:00');

        expect(window('last_month'))->toBe(['2028-02-01 00:00:00', '2028-02-29 23:59:59']);
    });
});

describe('rolling windows', function () {
    it('rolls back from this instant', function (string $expression, string $start) {
        expect(window($expression))->toBe([$start, '2026-08-06 14:30:00']);
    })->with([
        ['last_30_seconds', '2026-08-06 14:29:30'],
        ['last_15_minutes', '2026-08-06 14:15:00'],
        ['last_6_hours', '2026-08-06 08:30:00'],
        ['last_30_days', '2026-07-07 14:30:00'],
        ['last_2_weeks', '2026-07-23 14:30:00'],
        ['last_3_months', '2026-05-06 14:30:00'],
        ['last_1_years', '2025-08-06 14:30:00'],
    ]);

    it('accepts any size within the bound', function () {
        expect(TimeWindow::tryFrom('last_45_days'))->not->toBeNull()
            ->and(TimeWindow::tryFrom('last_99999_days'))->toBeNull();
    });

    it('does not overflow a month end', function () {
        Carbon::setTestNow('2026-03-31 09:00:00');

        expect(window('last_1_months')[0])->toBe('2026-02-28 09:00:00');
    });
});

describe('the closed grammar', function () {
    it('refuses what a general parser would have accepted', function (string $value) {
        expect(TimeWindow::tryFrom($value))->toBeNull();
    })->with([
        // strtotime resolves this to thirty hours in the *future*.
        'now-30d',
        // Read as January by strtotime, whatever the writer meant.
        '01/02/2026',
        '-30 days',
        'last month',
        'next month',
        'yesterday ',
        'LAST_30_DAYS',
        'last_30_fortnights',
    ]);

    it('names every window it accepts, so the contract can list them', function () {
        foreach (TimeWindow::names() as $name) {
            expect(TimeWindow::tryFrom($name))->not->toBeNull();
        }
    });
});

describe('reading intent', function () {
    it('suggests the window a relative expression was reaching for', function (string $value, string $window) {
        expect(TimeWindow::suggestFor($value))->toBe($window);
    })->with([
        ['now-30d', 'last_30_days'],
        ['-30 days', 'last_30_days'],
        ['30 days ago', 'last_30_days'],
        ['now-6h', 'last_6_hours'],
        ['15 mins', 'last_15_minutes'],
        ['now-2w', 'last_2_weeks'],
        ['3 months ago', 'last_3_months'],
    ]);

    it('reads a bare m as months, and spelled-out minutes as minutes', function () {
        // Decided, not incidental: "m" is ambiguous between months and minutes,
        // and a suggestion is a starting point the agent can override rather
        // than a decision made for it.
        expect(TimeWindow::suggestFor('now-3m'))->toBe('last_3_months')
            ->and(TimeWindow::suggestFor('now-30min'))->toBe('last_30_minutes')
            ->and(TimeWindow::suggestFor('now-30mo'))->toBe('last_30_months');
    });

    it('suggests nothing when there is nothing to read', function (string $value) {
        expect(TimeWindow::suggestFor($value))->toBeNull();
    })->with(['now', '2026-07-07', 'sometime last week']);
});

describe('column fit', function () {
    it('formats bounds as bare dates for a date column', function () {
        // A time here would exclude the boundary day wherever a date is stored
        // as text: '2026-07-07' sorts before '2026-07-07 00:00:00'.
        expect(window('last_30_days', ColumnType::Date))->toBe(['2026-07-07', '2026-08-06']);
    });

    it('refuses a window shorter than a day on a column with no time', function () {
        $window = TimeWindow::tryFrom('last_6_hours');

        expect($window?->appliesTo(ColumnType::Date))->toBeFalse()
            ->and($window?->appliesTo(ColumnType::Datetime))->toBeTrue();
    });

    it('allows a day or longer on a date column', function () {
        expect(TimeWindow::tryFrom('last_30_days')?->appliesTo(ColumnType::Date))->toBeTrue()
            ->and(TimeWindow::tryFrom('this_month')?->appliesTo(ColumnType::Date))->toBeTrue();
    });
});
