<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Rates\Actions\CreateRatePlan;
use App\Domains\Rates\Actions\SetRatePrices;
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
