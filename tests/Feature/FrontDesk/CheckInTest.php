<?php

use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Carbon\CarbonImmutable;
use Tests\Support\BookingForm;
use Tests\Support\TenantFixture;

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam')));

/**
 * A confirmed reservation arriving today in a clean, assigned room, with one
 * fully registered guest.
 *
 * @param  list<array<string, mixed>>  $documents
 */
function arrivingToday(TenantFixture $tenant, array $documents = [['type' => 'passport', 'number' => 'P1234567', 'nationality' => 'DE']]): Reservation
{
    return inTenant($tenant->property, function () use ($tenant, $documents) {
        $details = BookingForm::details($tenant->owner);
        $room = Room::query()->sole();
        $reservation = app(CreateReservation::class)->handle($tenant->owner, [...$details, 'room_id' => $room->id]);
        RoomStatus::query()->where('room_id', $room->id)->update(['status' => HousekeepingStatus::Clean]);
        $guest = app(CreateGuest::class)->handle($tenant->owner, ['name' => 'Arriving Guest', 'documents' => $documents]);
        ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);

        return $reservation;
    });
}

test('front desk checks in a ready arrival, marking the room occupied and recording one event', function () {
    $t = twoTenants();
    $reservation = arrivingToday($t->a);
    $actor = $t->a->member(OrganizationRole::FrontDesk);
    $this->actingAs($actor)->post(route('reservations.check-in', $reservation), ['_property' => $t->a->property->id])
        ->assertRedirect(route('reservations.show', $reservation));
    inTenant($t->a->property, function () use ($reservation, $actor) {
        expect($reservation->fresh()->status)->toBe(ReservationStatus::CheckedIn)
            ->and(RoomStatus::query()->where('room_id', $reservation->room_id)->sole()->status)->toBe(HousekeepingStatus::Occupied);
        $event = $reservation->lifecycleEvents()->where('type', 'checked_in')->sole();
        expect($event->actor_id)->toBe($actor->id)->and($event->snapshot['status'])->toBe('checked_in');
    });
});
