<?php

use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;

test('an auditor reads property KPIs with business dates currency and computation time', function () {
    $t = twoTenants();
    $this->freezeTime();
    $t->a->property->update(['business_date' => '2026-12-10']);
    inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    inTenant($t->a->otherProperty, function () use ($t) {
        app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
        InventoryRecord::query()->update(['sellable_count' => 50]);
    });

    $this->actingAs($t->a->member(OrganizationRole::Auditor))
        ->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->get(route('reports.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('reports/index')
        ->where('filters', ['from' => '2026-12-10', 'to' => '2026-12-10'])
        ->where('kpis.soldRoomNights', 1)->where('kpis.sellableRoomNights', 1)
        ->where('kpis.occupancy', 1)
        ->where('kpis.currencies.0.currency', 'USD')
        ->where('kpis.currencies.0.bookedRoomValue', 11000)
        ->where('refreshedAt', now()->toIso8601String()));
});
