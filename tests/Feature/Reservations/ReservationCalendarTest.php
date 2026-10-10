<?php

use App\Domains\Guests\Actions\AttachReservationGuest;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;

test('the calendar shows assigned reservations with guest names and detail links across their nights', function () {
    $t = twoTenants();
    [$reservation, $room] = inTenant($t->a->property, function () use ($t) {
        $details = BookingForm::details($t->a->owner);
        $room = Room::query()->sole();
        $reservation = app(CreateReservation::class)->handle($t->a->owner, [...$details, 'room_id' => $room->id]);
        app(AttachReservationGuest::class)->handle($t->a->owner, $reservation, ['name' => 'Staying Guest']);

        return [$reservation, $room];
    });
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)->get(route('rate-calendar', ['from' => '2026-12-09', 'to' => '2026-12-12']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('roomTypes.0.rooms', 1)
        ->where('roomTypes.0.rooms.0.id', $room->id)
        ->where('roomTypes.0.rooms.0.number', $room->number)
        ->has('roomTypes.0.rooms.0.reservations', 1)
        ->where('roomTypes.0.rooms.0.reservations.0', [
            'reservation_number' => $reservation->reservation_number,
            'arrival' => '2026-12-10', 'departure' => '2026-12-12',
            'guest_name' => 'Staying Guest', 'source' => 'phone', 'status' => 'confirmed',
            'url' => route('reservations.show', $reservation),
        ]));
});
