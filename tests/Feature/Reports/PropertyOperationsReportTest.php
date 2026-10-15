<?php

use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;

test('an auditor reads date-filtered operations only for the current property with a refresh time', function () {
    $t = twoTenants();
    $this->freezeTime();
    foreach ([$t->a->property, $t->a->otherProperty, $t->b->property] as $property) {
        $owner = $property->organization_id === $t->a->organization->id ? $t->a->owner : $t->b->owner;
        inTenant($property, function () use ($owner) {
            $reservation = app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
            $reservation->update(['status' => ReservationStatus::CheckedIn]);
        });
    }

    $this->actingAs($t->a->member(OrganizationRole::Auditor))
        ->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->get(route('reports.index', ['from' => '2026-12-11', 'to' => '2026-12-12']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('reports/index')
        ->where('operations.daily', [
            ['date' => '2026-12-11', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 1],
            ['date' => '2026-12-12', 'arrivals' => 0, 'departures' => 1, 'inHouse' => 0],
        ])
        ->where('refreshedAt', now()->toIso8601String())
        ->where('operations.housekeeping.0.rooms', 1));
});
