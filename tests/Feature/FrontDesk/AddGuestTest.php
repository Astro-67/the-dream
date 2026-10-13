<?php

use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Tests\Support\BookingForm;
use Tests\Support\TenantFixture;

const ADD_GUEST_PASSPORT = ['type' => 'passport', 'number' => 'P1234567', 'nationality' => 'DE'];

function addGuestStay(TenantFixture $tenant, ReservationStatus $status = ReservationStatus::CheckedIn): Reservation
{
    return inTenant($tenant->property, function () use ($tenant, $status) {
        $reservation = app(CreateReservation::class)->handle($tenant->owner, BookingForm::details($tenant->owner));
        $reservation->update(['status' => $status]);

        return $reservation;
    });
}

/** @return array{guests: int, events: int} */
function addGuestCounts(TenantFixture $tenant, Reservation $reservation): array
{
    return inTenant($tenant->property, fn () => [
        'guests' => ReservationGuest::query()->where('reservation_id', $reservation->id)->count(),
        'events' => $reservation->lifecycleEvents()->where('type', 'guest_added')->count(),
    ]);
}

test('front desk adds a registered guest in house, recording one guest_added event', function () {
    $t = twoTenants();
    $reservation = addGuestStay($t->a);
    $actor = $t->a->member(OrganizationRole::FrontDesk);
    $this->actingAs($actor)->post(route('reservations.add-guest', $reservation), ['_property' => $t->a->property->id, 'name' => 'Late Arrival', 'documents' => [ADD_GUEST_PASSPORT]])
        ->assertSessionHasNoErrors()->assertRedirect(route('reservations.guests.create', $reservation));
    expect(addGuestCounts($t->a, $reservation))->toBe(['guests' => 1, 'events' => 1]);
    inTenant($t->a->property, function () use ($reservation, $actor) {
        $event = $reservation->lifecycleEvents()->where('type', 'guest_added')->sole();
        expect($event->actor_id)->toBe($actor->id)->and($event->snapshot['guest_id'])->toBe(Guest::query()->sole()->id);
    });
});
