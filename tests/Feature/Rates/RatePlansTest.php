<?php

use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Rates\Actions\CreateRatePlan;
use App\Domains\Rates\Actions\SetRatePrices;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\InventoryForm;

test('bulk prices select weekdays and audit once with the range and minor units', function () {
    $t = twoTenants();
    [$room, $plan] = inTenant($t->a->property, function () use ($t) {
        $room = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());

        return [$room, app(CreateRatePlan::class)->handle($t->a->owner, ['name' => 'Breakfast', 'code' => 'BB', 'currency' => 'USD', 'room_type_ids' => [$room->id]])];
    });
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->put(route('rate-plans.prices.update', $plan), [
        '_property' => $t->a->property->id, 'room_type_id' => $room->id,
        'from' => '2026-10-05', 'to' => '2026-10-11', 'weekdays' => [1, 5],
        'base_price' => 12000, 'extra_adult' => 3000, 'extra_child' => 1500,
    ])->assertSessionHasNoErrors()->assertRedirect(route('rate-plans.prices', $plan));
    $this->get(route('rate-plans.prices', $plan))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('rates/prices')->has('prices', 2)->where('prices.0.date', '2026-10-05')->where('prices.1.date', '2026-10-09')->where('prices.0.base_price', 12000));
    $this->put(route('rate-plans.prices.update', $plan), ['_property' => $t->a->property->id, 'room_type_id' => $room->id, 'from' => 'soon', 'to' => '2026-10-11', 'weekdays' => [1], 'base_price' => 1, 'extra_adult' => 0, 'extra_child' => 0])
        ->assertSessionHasErrors('from');
    $this->put(route('rate-plans.prices.update', $plan), ['_property' => $t->a->property->id, 'room_type_id' => $room->id, 'from' => '2026-01-01', 'to' => '2028-01-02', 'weekdays' => [1], 'base_price' => 1, 'extra_adult' => 0, 'extra_child' => 0])
        ->assertSessionHasErrors('to');
    $audit = Activity::query()->where('description', 'Rate prices updated')->sole();
    expect($audit->getExtraProperty('attributes'))->toEqual(['base_price' => 12000, 'extra_adult' => 3000, 'extra_child' => 1500])
        ->and($audit->getExtraProperty('from'))->toBe('2026-10-05')->and($audit->getExtraProperty('to'))->toBe('2026-10-11');
});

test('a property manager creates a rate plan for a room type and sees it listed', function () {
    $t = twoTenants();
    $room = inTenant($t->a->property, fn () => app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType()));
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))
        ->post(route('rate-plans.store'), ['_property' => $t->a->property->id, 'name' => 'Bed and breakfast', 'code' => 'BB', 'currency' => 'USD', 'room_type_ids' => [$room->id]])
        ->assertRedirect(route('rate-plans.index'));
    $this->get(route('rate-plans.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('rates/index')->has('ratePlans', 1)->where('ratePlans.0.code', 'BB')->where('ratePlans.0.currency', 'USD')->where('canManage', true));
});

test('front desk previews a quote and sees nightly prices without managing them', function () {
    $t = twoTenants();
    [$room, $plan] = inTenant($t->a->property, function () use ($t) {
        $room = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());
        $plan = app(CreateRatePlan::class)->handle($t->a->owner, ['name' => 'Breakfast', 'code' => 'BB', 'currency' => 'USD', 'room_type_ids' => [$room->id]]);
        app(SetRatePrices::class)->handle($t->a->owner, $plan, ['room_type_id' => $room->id, 'from' => '2026-12-01', 'to' => '2026-12-10', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'base_price' => 10000, 'extra_adult' => 2500, 'extra_child' => 1000]);

        return [$room, $plan];
    });
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));

    $this->get(route('rate-plans.quote', [$plan, 'room_type_id' => $room->id, 'arrival' => '2026-12-09', 'departure' => '2026-12-11', 'adults' => 2, 'children' => 1]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('rates/quote')
        ->where('quote.currency', 'USD')->where('quote.total', 22000)->has('quote.nights', 2)->where('quote.nights.0.extra_children', 1000)->where('quote.unquotable', null)
        ->where('canManage', false));
    $this->get(route('rate-plans.quote', $plan))->assertOk()->assertInertia(fn (Assert $page) => $page->where('quote', null));
    $this->get(route('rate-plans.quote', [$plan, 'room_type_id' => $room->id, 'arrival' => 'soon']))->assertSessionHasErrors('arrival');
    $this->put(route('rate-plans.prices.update', $plan), ['_property' => $t->a->property->id])->assertForbidden();
});
