<?php

use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Actions\CreateRatePlan;
use App\Domains\Rates\Actions\SetRatePrices;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Rates\Models\RatePrice;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Tests\Support\InventoryForm;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

/** Bravo's priced rate plan, named so a leak is easy to spot. @return array{0: RatePlan, 1: RoomType} */
$bravoPlan = fn (TwoTenants $t): array => inTenant($t->b->property, function () use ($t) {
    $roomType = app(CreateRoomType::class)->handle($t->b->owner, InventoryForm::roomType(['name' => 'Bravo Secret Suite', 'code' => 'BRVX']));
    $plan = app(CreateRatePlan::class)->handle($t->b->owner, ['name' => 'Bravo Secret Rate', 'code' => 'BRVRATE', 'currency' => 'USD', 'room_type_ids' => [$roomType->id]]);
    app(SetRatePrices::class)->handle($t->b->owner, $plan, ['room_type_id' => $roomType->id, 'from' => '2026-12-01', 'to' => '2026-12-03', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'base_price' => 987654, 'extra_adult' => 0, 'extra_child' => 0]);

    return [$plan, $roomType];
});

/** An Alpha plan on an Alpha room type. */
$alphaPlan = fn (TwoTenants $t): RatePlan => inTenant($t->a->property, function () use ($t) {
    $roomType = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());

    return app(CreateRatePlan::class)->handle($t->a->owner, ['name' => 'Alpha Rate', 'code' => 'AR', 'currency' => 'USD', 'room_type_ids' => [$roomType->id]]);
});

/** @return array<string, mixed> */
$bravoSnapshot = fn (TwoTenants $t): array => inTenant($t->b->organization, fn () => [
    'plans' => RatePlan::query()->with('roomTypes:id')->orderBy('id')->get()->map(fn (RatePlan $p) => [...$p->only(['id', 'property_id', 'name', 'code']), 'roomTypes' => $p->roomTypes->pluck('id')->all()])->all(),
    'prices' => RatePrice::query()->orderBy('id')->get()->map->only(['id', 'rate_plan_id', 'room_type_id', 'base_price', 'extra_adult', 'extra_child'])->all(),
]);

$prices = fn (string $roomTypeId, string $propertyId) => ['_property' => $propertyId, 'room_type_id' => $roomTypeId, 'from' => '2026-12-01', 'to' => '2026-12-03', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'base_price' => 1, 'extra_adult' => 0, 'extra_child' => 0];

// Writes leave Bravo's plans and prices unchanged.
$writeAttempt = fn (Closure $send) => function (TwoTenants $t) use ($send, $bravoPlan, $bravoSnapshot) {
    [$plan, $roomType] = $bravoPlan($t);
    $before = $bravoSnapshot($t);
    $responses = $send->call($this, $t, $plan, $roomType);
    expect($bravoSnapshot($t))->toBe($before);

    return $responses;
};

return [
    'rate-plans.index' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoPlan) {
        $bravoPlan($t);

        return array_map(fn ($member) => $this->actingAs($member)->get(route('rate-plans.index'))->assertDontSee('Bravo Secret Rate'), $t->a->members());
    }),
    'rate-plans.create' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoPlan) {
        $bravoPlan($t);

        return $this->actingAs($t->a->owner)->get(route('rate-plans.create'))->assertOk()->assertDontSee('Bravo Secret Suite')->assertDontSee('Bravo Secret Rate');
    }),
    'rate-plans.store' => RouteCoverage::attempt($writeAttempt(fn (TwoTenants $t, RatePlan $plan, RoomType $roomType) => [
        $this->actingAs($t->a->owner)->post(route('rate-plans.store'), ['_property' => $t->b->property->id, 'name' => 'Hijacked', 'code' => 'HJ', 'currency' => 'USD', 'room_type_ids' => [$roomType->id]])->assertForbidden(),
        // Bravo's room type can't be attached to an Alpha plan.
        $this->actingAs($t->a->owner)->post(route('rate-plans.store'), ['_property' => $t->a->property->id, 'name' => 'Hijacked', 'code' => 'HJ', 'currency' => 'USD', 'room_type_ids' => [$roomType->id]])->assertSessionHasErrors('room_type_ids.0'),
        // Bravo's plan can't be the base of an Alpha plan.
        $this->actingAs($t->a->owner)->post(route('rate-plans.store'), ['_property' => $t->a->property->id, 'name' => 'Hijacked', 'code' => 'HJ', 'base_rate_plan_id' => $plan->id, 'adjustment_kind' => 'percent', 'adjustment' => -10])->assertSessionHasErrors('base_rate_plan_id'),
    ])),
    'rate-plans.prices' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoPlan) {
        [$plan] = $bravoPlan($t);

        return $this->actingAs($t->a->owner)->get(route('rate-plans.prices', $plan->id))->assertNotFound();
    }),
    'rate-plans.quote' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoPlan, $alphaPlan) {
        [$plan, $roomType] = $bravoPlan($t);
        $alpha = $alphaPlan($t);

        return [
            $this->actingAs($t->a->owner)->get(route('rate-plans.quote', [$plan->id, 'room_type_id' => $roomType->id, 'arrival' => '2026-12-01', 'departure' => '2026-12-02', 'adults' => 2, 'children' => 0]))->assertNotFound(),
            // Alpha's plan can't be quoted on Bravo's room type.
            $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->get(route('rate-plans.quote', [$alpha->id, 'room_type_id' => $roomType->id, 'arrival' => '2026-12-01', 'departure' => '2026-12-02', 'adults' => 2, 'children' => 0]))->assertSessionHasErrors('room_type_id'),
        ];
    }),
    'rate-plans.prices.update' => RouteCoverage::attempt($writeAttempt(function (TwoTenants $t, RatePlan $plan, RoomType $roomType) use ($alphaPlan, $prices) {
        $alpha = $alphaPlan($t);

        return [
            $this->actingAs($t->a->owner)->put(route('rate-plans.prices.update', $plan->id), $prices($roomType->id, $t->a->property->id))->assertNotFound(),
            $this->actingAs($t->a->owner)->put(route('rate-plans.prices.update', $plan->id), $prices($roomType->id, $t->b->property->id))->assertForbidden(),
            // Alpha's plan can't be priced on Bravo's room type.
            $this->actingAs($t->a->owner)->put(route('rate-plans.prices.update', $alpha->id), $prices($roomType->id, $t->a->property->id))->assertSessionHasErrors('room_type_id'),
        ];
    })),
    'rate-plans.deactivate' => RouteCoverage::attempt($writeAttempt(fn (TwoTenants $t, RatePlan $plan) => [
        $this->actingAs($t->a->owner)->post(route('rate-plans.deactivate', $plan->id), ['_property' => $t->a->property->id])->assertNotFound(),
        $this->actingAs($t->a->owner)->post(route('rate-plans.deactivate', $plan->id), ['_property' => $t->b->property->id])->assertForbidden(),
    ])),
];
