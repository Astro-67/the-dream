<?php

use App\Domains\Rates\Enums\AdjustmentKind;

test('a percentage adjusts every amount and rounds half up to minor units', function () {
    expect(AdjustmentKind::Percent->apply(-10, 12345, 2500, 1005))->toBe(['base_price' => 11111, 'extra_adult' => 2250, 'extra_child' => 905])
        ->and(AdjustmentKind::Percent->apply(15, 999, 1, 0))->toBe(['base_price' => 1149, 'extra_adult' => 1, 'extra_child' => 0])
        ->and(AdjustmentKind::Percent->apply(-100, 5000, 100, 100))->toBe(['base_price' => 0, 'extra_adult' => 0, 'extra_child' => 0]);
});

test('an amount shifts only the base occupancy price and never goes below zero', function () {
    expect(AdjustmentKind::Amount->apply(-1500, 10000, 2500, 1000))->toBe(['base_price' => 8500, 'extra_adult' => 2500, 'extra_child' => 1000])
        ->and(AdjustmentKind::Amount->apply(-20000, 10000, 2500, 1000))->toBe(['base_price' => 0, 'extra_adult' => 2500, 'extra_child' => 1000]);
});
