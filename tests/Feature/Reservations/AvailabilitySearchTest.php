<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Rates\Actions\CreateRatePlan;
use App\Domains\Rates\Actions\SetRatePrices;
use App\Domains\Rates\Models\RatePrice;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InventoryForm;
use Tests\Support\TenantFixture;

function availabilityStay(array $overrides = []): array
{
    return ['arrival' => '2026-12-10', 'departure' => '2026-12-12', 'adults' => 2, 'children' => 1, ...$overrides];
}

function availabilityRooms(TenantFixture $tenant): array
{
    return inTenant($tenant->property, function () use ($tenant) {
        $type = app(CreateRoomType::class)->handle($tenant->owner, InventoryForm::roomType());
        foreach (['101', '102'] as $number) {
            app(CreateRoom::class)->handle($tenant->owner, InventoryForm::room($type->id, ['number' => $number]));
        }
        $plan = app(CreateRatePlan::class)->handle($tenant->owner, ['name' => 'Breakfast', 'code' => 'BB', 'currency' => 'USD', 'room_type_ids' => [$type->id]]);
        app(SetRatePrices::class)->handle($tenant->owner, $plan, ['room_type_id' => $type->id, 'from' => '2026-12-10', 'to' => '2026-12-11', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'base_price' => 10000, 'extra_adult' => 2500, 'extra_child' => 1000]);

        return [$type, $plan];
    });
}

test('availability uses the least sellable minus sold count and excludes exhausted or missing nights', function () {
    $t = twoTenants();
    [$type] = availabilityRooms($t->a);
    inTenant($t->a->property, fn () => InventoryRecord::query()->where('room_type_id', $type->id)->whereDate('date', '2026-12-11')->update(['sold_count' => 1]));
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)->get(route('reservations.availability', availabilityStay()))
        ->assertInertia(fn (Assert $page) => $page->where('results.0.available', 1));
    inTenant($t->a->property, fn () => InventoryRecord::query()->where('room_type_id', $type->id)->whereDate('date', '2026-12-11')->update(['sold_count' => 2]));
    $this->get(route('reservations.availability', availabilityStay()))->assertInertia(fn (Assert $page) => $page->has('results', 0));
    inTenant($t->a->property, fn () => InventoryRecord::query()->where('room_type_id', $type->id)->whereDate('date', '2026-12-11')->delete());
    $this->get(route('reservations.availability', availabilityStay()))->assertInertia(fn (Assert $page) => $page->has('results', 0));
});

test('only complete active attached rate plans are quoted including derived prices', function () {
    $t = twoTenants();
    [$type, $base] = availabilityRooms($t->a);
    $derived = inTenant($t->a->property, function () use ($t, $base) {
        return app(CreateRatePlan::class)->handle($t->a->owner, ['name' => 'Discount', 'code' => 'DISC', 'base_rate_plan_id' => $base->id, 'adjustment_kind' => 'percent', 'adjustment' => -10]);
    });
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)->get(route('reservations.availability', availabilityStay()))
        ->assertInertia(fn (Assert $page) => $page->has('results.0.plans', 2)->where('results.0.plans.1.id', $derived->id)->where('results.0.plans.1.total', 19800));
    inTenant($t->a->property, fn () => $derived->forceFill(['deactivated_at' => now()])->save());
    $this->get(route('reservations.availability', availabilityStay()))->assertInertia(fn (Assert $page) => $page->has('results.0.plans', 1));
    inTenant($t->a->property, fn () => RatePrice::query()->where('rate_plan_id', $base->id)->whereDate('date', '2026-12-11')->delete());
    $this->get(route('reservations.availability', availabilityStay()))->assertInertia(fn (Assert $page) => $page->has('results', 0));
});

test('occupancy limits exclude room types', function (array $counts) {
    $t = twoTenants();
    availabilityRooms($t->a);
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)->get(route('reservations.availability', availabilityStay($counts)))
        ->assertInertia(fn (Assert $page) => $page->has('results', 0));
})->with([[['adults' => 3, 'children' => 0]], [['adults' => 1, 'children' => 2]], [['adults' => 2, 'children' => 2]]]);

test('permitted staff can search and housekeeping cannot', function (OrganizationRole $role, int $status) {
    $t = twoTenants();
    $this->actingAs($t->a->member($role))->get(route('reservations.availability'))->assertStatus($status);
})->with([
    [OrganizationRole::Owner, 200], [OrganizationRole::GroupOperationsManager, 200],
    [OrganizationRole::PropertyManager, 200], [OrganizationRole::FrontDesk, 200],
    [OrganizationRole::Housekeeping, 403],
]);

test('search rejects invalid or unbounded stays', function (array $fields, string $error) {
    $t = twoTenants();
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)->getJson(route('reservations.availability', availabilityStay($fields)))
        ->assertUnprocessable()->assertJsonValidationErrors($error);
})->with([
    [['arrival' => 'not-a-date'], 'arrival'],
    [['departure' => '2026-12-10'], 'departure'],
    [['departure' => '2027-12-12'], 'departure'],
    [['adults' => 0], 'adults'],
    [['children' => -1], 'children'],
]);

test('front desk quotes every night and the stay total without booking', function () {
    $t = twoTenants();
    [$type, $plan] = availabilityRooms($t->a);

    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))
        ->get(route('reservations.availability', availabilityStay()))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('reservations/availability')
            ->has('results', 1)->where('results.0.id', $type->id)
            ->where('results.0.available', 2)->has('results.0.plans', 1)
            ->where('results.0.plans.0.id', $plan->id)
            ->where('results.0.plans.0.currency', 'USD')
            ->where('results.0.plans.0.total', 22000)
            ->where('results.0.plans.0.nights.0.total', 11000)
            ->where('results.0.plans.0.nights.1.date', '2026-12-11'));
});
