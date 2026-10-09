<?php

namespace App\Domains\Rates\Enums;

/**
 * How a derived rate plan's prices differ from its base plan's.
 */
enum AdjustmentKind: string
{
    /** Every amount changes by a whole percentage, rounded half up to minor units. */
    case Percent = 'percent';

    /** The base occupancy price changes by a fixed number of minor units; extras stay. */
    case Amount = 'amount';

    /** @return array{base_price: int, extra_adult: int, extra_child: int} */
    public function apply(int $adjustment, int $basePrice, int $extraAdult, int $extraChild): array
    {
        $percent = fn (int $amount): int => max(0, TaxRounding::HalfUp->round($amount * (100 + $adjustment), 100));

        return match ($this) {
            self::Percent => ['base_price' => $percent($basePrice), 'extra_adult' => $percent($extraAdult), 'extra_child' => $percent($extraChild)],
            self::Amount => ['base_price' => max(0, $basePrice + $adjustment), 'extra_adult' => $extraAdult, 'extra_child' => $extraChild],
        };
    }
}
