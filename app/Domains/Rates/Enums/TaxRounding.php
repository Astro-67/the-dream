<?php

namespace App\Domains\Rates\Enums;

use RoundingMode;

/**
 * How a tax line is rounded to whole minor units.
 */
enum TaxRounding: string
{
    case HalfUp = 'half_up';
    case Up = 'up';
    case Down = 'down';

    public function round(float $minor): int
    {
        return (int) round($minor, 0, match ($this) {
            self::HalfUp => RoundingMode::HalfAwayFromZero,
            self::Up => RoundingMode::AwayFromZero,
            self::Down => RoundingMode::TowardsZero,
        });
    }
}
