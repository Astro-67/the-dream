<?php

namespace App\Domains\Rates\Enums;

use InvalidArgumentException;

/**
 * How a tax line is rounded to whole minor units.
 */
enum TaxRounding: string
{
    case HalfUp = 'half_up';
    case Up = 'up';
    case Down = 'down';

    public function round(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException(__('The rounding denominator must be positive.'));
        }

        $whole = intdiv($numerator, $denominator);
        $remainder = abs($numerator % $denominator);
        $increment = match ($this) {
            self::HalfUp => $remainder >= $denominator - $remainder,
            self::Up => $remainder > 0,
            self::Down => false,
        };

        return $whole + ($increment ? ($numerator < 0 ? -1 : 1) : 0);
    }
}
