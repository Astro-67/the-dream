<?php

namespace App\Domains\Rates;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Inclusive calendar dates, filtered by ISO weekdays (Monday = 1, Sunday = 7). */
final class DateRange
{
    /**
     * @param  list<int>  $weekdays
     * @return list<string>
     */
    public static function dates(string $from, string $to, array $weekdays = [1, 2, 3, 4, 5, 6, 7]): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $from);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to);
        if (! $start || ! $end || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to || $end->lt($start) || $start->diffInDays($end) > 730 || $weekdays === [] || array_diff($weekdays, [1, 2, 3, 4, 5, 6, 7]) !== []) {
            throw new InvalidArgumentException('Use a valid date range of at most 731 dates and ISO weekdays.');
        }
        $dates = [];
        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            if (in_array($date->dayOfWeekIso, $weekdays, true)) {
                $dates[] = $date->format('Y-m-d');
            }
        }

        return $dates;
    }
}
