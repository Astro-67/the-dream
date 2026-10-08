<?php

use App\Domains\Rates\DateRange;

test('a range keeps only the chosen ISO weekdays, both ends included', function () {
    // 2026-10-05 is a Monday.
    expect(DateRange::dates('2026-10-05', '2026-10-12', [1, 7]))->toBe(['2026-10-05', '2026-10-11', '2026-10-12'])
        ->and(DateRange::dates('2027-02-28', '2027-03-01'))->toBe(['2027-02-28', '2027-03-01']);
});

test('a reversed, malformed or over-long range is refused', function (string $from, string $to, array $weekdays) {
    expect(fn () => DateRange::dates($from, $to, $weekdays))->toThrow(InvalidArgumentException::class);
})->with([
    'reversed' => ['2026-10-12', '2026-10-05', [1]],
    'not a date' => ['2026-02-30', '2026-03-05', [1]],
    'over two years' => ['2026-01-01', '2028-01-02', [1]],
    'no weekdays' => ['2026-10-05', '2026-10-12', []],
    'weekday 0' => ['2026-10-05', '2026-10-12', [0]],
]);
