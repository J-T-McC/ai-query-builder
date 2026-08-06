<?php

declare(strict_types=1);

use JTMcC\AiQueryBuilder\Schema\Enums\ColumnType;

describe('temporal values', function () {
    it('accepts an absolute date or date-time', function (string $value) {
        expect(ColumnType::Datetime->accepts($value))->toBeTrue();
    })->with([
        '2026-07-07',
        '2026-07-07 09:30',
        '2026-07-07 09:30:15',
        '2026-07-07T09:30:15',
        '2026-07-07T09:30:15+01:00',
    ]);

    it('refuses a relative expression rather than evaluating it', function (string $value) {
        expect(ColumnType::Datetime->accepts($value))->toBeFalse();
    })->with([
        // The reported failure, and the ones a laxer parser would have let
        // through: strtotime resolves all three, and accepting any of them
        // would bind the literal string to SQL and mean nothing at all.
        'now-30d',
        'now',
        '-30 days',
        'last monday',
        'yesterday',
    ]);

    it('refuses a date that does not exist', function () {
        expect(ColumnType::Date->accepts('2026-02-30'))->toBeFalse();
    });

    it('refuses a value with anything trailing it', function () {
        expect(ColumnType::Date->accepts('2026-07-07 or 1=1'))->toBeFalse();
    });

    it('refuses a number as a date', function () {
        expect(ColumnType::Date->accepts(1_767_744_000))->toBeFalse();
    });
});

describe('scalar values', function () {
    it('accepts a numeric string for a numeric column', function () {
        // The database compares these correctly, so refusing them would cost a
        // round trip and buy nothing.
        expect(ColumnType::Integer->accepts('42'))->toBeTrue()
            ->and(ColumnType::Number->accepts('42.5'))->toBeTrue()
            ->and(ColumnType::Number->accepts(42))->toBeTrue();
    });

    it('refuses text where a number belongs', function () {
        expect(ColumnType::Integer->accepts('forty two'))->toBeFalse()
            ->and(ColumnType::Number->accepts('1,000'))->toBeFalse();
    });

    it('refuses a fractional value for a whole-number column', function () {
        expect(ColumnType::Integer->accepts(42.5))->toBeFalse();
    });

    it('accepts the ways a boolean is written', function (mixed $value) {
        expect(ColumnType::Boolean->accepts($value))->toBeTrue();
    })->with([true, false, 0, 1, '0', '1', 'true', 'FALSE']);

    it('refuses a string column nothing, because nothing there is wrong', function (mixed $value) {
        expect(ColumnType::String->accepts($value))->toBeTrue();
    })->with(['text', 42, true]);
});

it('says how to write the value it wants', function () {
    expect(ColumnType::Datetime->hint())->toContain('YYYY-MM-DD HH:MM:SS')
        ->and(ColumnType::Datetime->hint())->toContain('not evaluated');
});

it('reports that a string column constrains nothing', function () {
    expect(ColumnType::String->constrainsValues())->toBeFalse()
        ->and(ColumnType::Date->constrainsValues())->toBeTrue();
});
