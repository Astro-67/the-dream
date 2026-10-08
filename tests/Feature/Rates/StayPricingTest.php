<?php

use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Rates\Actions\CreateRatePlan;
use App\Domains\Rates\Actions\SetRatePrices;
use App\Domains\Rates\StayPricing;
use Tests\Support\InventoryForm;

// Deluxe Double with 3 adults allowed: base occupancy 2, up to 3 adults, 1 child, 4 guests.
// Returns a quote function for a fresh plan priced with $prices.
function pricedPlan(array $prices): Closure
{
    $t = twoTenants();
    [$plan, $room] = inTenant($t->a->property, function () use ($t, $prices) {
        $room = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType(['max_adults' => 3, 'max_occupancy' => 4]));
        $plan = app(CreateRatePlan::class)->handle($t->a->owner, ['name' => 'Breakfast', 'code' => 'BB', 'currency' => 'USD', 'room_type_ids' => [$room->id]]);
        foreach ($prices as $price) {
            app(SetRatePrices::class)->handle($t->a->owner, $plan, ['room_type_id' => $room->id, 'weekdays' => [1, 2, 3, 4, 5, 6, 7], ...$price]);
        }

        return [$plan, $room];
    });

    return fn (string $arrival, string $departure, int $adults, int $children): array => inTenant($t->a->property, function () use ($plan, $room, $arrival, $departure, $adults, $children) {
        $quote = app(StayPricing::class)->quote($plan, $room, $arrival, $departure, $adults, $children);

        return ['nights' => $quote->nights, 'total' => $quote->total(), 'unquotable' => $quote->unquotable, 'currency' => $quote->currency->value];
    });
}

$season = ['from' => '2026-12-01', 'to' => '2026-12-31', 'base_price' => 10000, 'extra_adult' => 2500, 'extra_child' => 1000];

test('base occupancy is charged the base price for each night, departure night excluded', function () use ($season) {
    $quote = pricedPlan([$season])('2026-12-10', '2026-12-12', 2, 0);

    expect($quote['unquotable'])->toBeNull()
        ->and($quote['currency'])->toBe('USD')
        ->and($quote['total'])->toBe(20000)
        ->and($quote['nights'])->toBe([
            ['date' => '2026-12-10', 'base_price' => 10000, 'extra_adults' => 0, 'extra_children' => 0, 'total' => 10000],
            ['date' => '2026-12-11', 'base_price' => 10000, 'extra_adults' => 0, 'extra_children' => 0, 'total' => 10000],
        ]);
});

test('guests beyond base occupancy pay the extra-adult and extra-child amounts', function () use ($season) {
    // 3 adults + 1 child, base 2: one extra adult (2500) and one extra child (1000).
    expect(pricedPlan([$season])('2026-12-10', '2026-12-11', 3, 1)['nights'][0])
        ->toBe(['date' => '2026-12-10', 'base_price' => 10000, 'extra_adults' => 2500, 'extra_children' => 1000, 'total' => 13500]);
});

test('a child fills a free base place before being charged as extra', function () use ($season) {
    expect(pricedPlan([$season])('2026-12-10', '2026-12-11', 1, 1)['total'])->toBe(10000);
});

test('each night takes the price set for its own date', function () {
    $quote = pricedPlan([
        ['from' => '2026-12-01', 'to' => '2026-12-31', 'base_price' => 30000, 'extra_adult' => 0, 'extra_child' => 0],
        ['from' => '2027-01-01', 'to' => '2027-01-31', 'base_price' => 12000, 'extra_adult' => 0, 'extra_child' => 0],
    ])('2026-12-31', '2027-01-02', 2, 0);

    expect(array_column($quote['nights'], 'base_price'))->toBe([30000, 12000])->and($quote['total'])->toBe(42000);
});

test('a night with no price makes the stay unquotable and names the date', function () use ($season) {
    $quote = pricedPlan([$season])('2026-12-31', '2027-01-02', 2, 0);

    expect($quote['unquotable'])->toBe('No price is set for 2027-01-01.')->and($quote['nights'])->toBe([]);
});

test('a stay over the room type\'s occupancy is unquotable', function () use ($season) {
    $quote = pricedPlan([$season]);

    expect($quote('2026-12-10', '2026-12-11', 3, 2)['unquotable'])->toBe('Deluxe Double takes at most 4 guests.')
        ->and($quote('2026-12-10', '2026-12-11', 0, 1)['unquotable'])->toBe('A stay needs at least one adult.');
});

test('departure must be after arrival', function () use ($season) {
    expect(pricedPlan([$season])('2026-12-10', '2026-12-10', 2, 0)['unquotable'])->toBe('Departure must be after arrival.');
});

test('a later edit replaces the price of the dates it covers', function () use ($season) {
    $quote = pricedPlan([$season, ['from' => '2026-12-11', 'to' => '2026-12-11', 'base_price' => 15000, 'extra_adult' => 0, 'extra_child' => 0]]);

    expect(array_column($quote('2026-12-10', '2026-12-12', 2, 0)['nights'], 'base_price'))->toBe([10000, 15000]);
});
