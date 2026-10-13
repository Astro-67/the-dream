<?php

namespace App\Domains\Rates\Enums;

/**
 * What a cancellation or no-show fee charges out of the stay's nightly prices.
 */
enum FeeCharge: string
{
    case Percent = 'percent';
    case FirstNight = 'first_night';
    case FullStay = 'full_stay';

    /**
     * The fee in minor units; a percentage of the stay rounds half up.
     *
     * @param  list<int>  $nights  the stay's nightly totals, in date order
     */
    public function amount(array $nights, ?int $percent): int
    {
        return match ($this) {
            self::FirstNight => $nights[0] ?? 0,
            self::FullStay => array_sum($nights),
            self::Percent => TaxRounding::HalfUp->round(array_sum($nights) * (int) $percent, 100),
        };
    }
}
