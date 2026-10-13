<?php

use App\Domains\FrontDesk\Models\BusinessDateClose;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia;
use Tests\Support\BookingForm;

test('a property manager starts night audit and reads the room charge summary', function () {
    $tenants = twoTenants();
    $property = $tenants->a->property;
    $property->update(['business_date' => '2026-12-10']);
    $reservation = inTenant($property, function () use ($tenants) {
        $reservation = app(CreateReservation::class)->handle($tenants->a->owner, BookingForm::details($tenants->a->owner));
        $reservation->update(['status' => ReservationStatus::CheckedIn]);

        return $reservation;
    });
    $actor = $tenants->a->member(OrganizationRole::PropertyManager);
    $response = $this->actingAs($actor)->post(route('night-audit.store'), ['_property' => $property->id, 'business_date' => '2026-12-10']);
    $close = inTenant($property, fn () => BusinessDateClose::query()->sole());
    $response->assertRedirect(route('night-audit.show', $close));
    $this->get(route('night-audit.show', $close))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('night-audit/show')
        ->where('close.business_date', '2026-12-10')
        ->where('close.actor', $actor->name)
        ->has('charges.data', 1)
        ->where('charges.data.0.reservation_number', $reservation->reservation_number)
        ->where('charges.data.0.amount', 11000)
        ->where('charges.data.0.currency', 'USD'));
});
