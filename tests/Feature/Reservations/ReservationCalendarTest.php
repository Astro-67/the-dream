<?php

use App\Domains\Guests\Actions\AttachReservationGuest;
use App\Domains\Inventory\Models\Room;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Reservations\Actions\CancelReservation;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Actions\VoidReservation;
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
            'is_overstay' => false, 'display_departure' => '2026-12-12',
            'reservation_number' => $reservation->reservation_number,
            'arrival' => '2026-12-10', 'departure' => '2026-12-12',
            'guest_name' => 'Staying Guest', 'source' => 'phone', 'status' => 'confirmed',
            'url' => route('reservations.show', $reservation),
        ]));
});

test('unassigned counts exclude departure dates and cancelled or void reservations', function () {
    $t = twoTenants();
    inTenant($t->a->property, function () use ($t) {
        $details = BookingForm::details($t->a->owner);
        foreach (['cancelled', 'void'] as $status) {
            $reservation = app(CreateReservation::class)->handle($t->a->owner, $details);
            $action = $status === 'cancelled' ? CancelReservation::class : VoidReservation::class;
            app($action)->handle($t->a->owner, $reservation, 'Created in error');
        }
        app(CreateReservation::class)->handle($t->a->owner, $details);
    });
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)
        ->get(route('rate-calendar', ['from' => '2026-12-11', 'to' => '2026-12-13']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('roomTypes.0.unassigned', ['2026-12-11' => 1, '2026-12-12' => 0, '2026-12-13' => 0])
        ->has('roomTypes.0.rooms.0.reservations', 0));
});

test('the departures calendar includes departures on its default first day without counting another night', function () {
    $t = twoTenants();
    $reservation = inTenant($t->a->property, function () use ($t) {
        $details = BookingForm::details($t->a->owner);

        return app(CreateReservation::class)->handle($t->a->owner, [...$details, 'room_id' => Room::query()->sole()->id]);
    });
    $t->a->property->update(['business_date' => '2026-12-12']);

    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->actingAs($t->a->owner)->get(route('rate-calendar', ['list' => 'departures']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('from', '2026-12-12')
        ->has('roomTypes.0.rooms.0.reservations', 1)
        ->where('roomTypes.0.rooms.0.reservations.0.reservation_number', $reservation->reservation_number)
        ->where('roomTypes.0.rooms.0.reservations.0.departure', '2026-12-12')
        ->where('roomTypes.0.unassigned.2026-12-12', 0)
        ->where('roomTypes.0.sellable.2026-12-12', 1));
});

test('the calendar keeps reservations guests and counts inside the selected property', function () {
    $t = twoTenants();
    foreach ([$t->a->property, $t->a->otherProperty, $t->b->property] as $property) {
        $owner = $property->organization_id === $t->a->organization->id ? $t->a->owner : $t->b->owner;
        inTenant($property, function () use ($owner) {
            $details = BookingForm::details($owner);
            $room = Room::query()->sole();
            $reservation = app(CreateReservation::class)->handle($owner, [...$details, 'room_id' => $room->id]);
            app(AttachReservationGuest::class)->handle($owner, $reservation, ['name' => 'Guest '.$reservation->property_id]);
        });
    }
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)
        ->get(route('rate-calendar', ['from' => '2026-12-10', 'to' => '2026-12-12']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('roomTypes', 1)->has('roomTypes.0.rooms', 1)->has('roomTypes.0.rooms.0.reservations', 1)
        ->where('roomTypes.0.rooms.0.reservations.0.guest_name', 'Guest '.$t->a->property->id)
        ->where('roomTypes.0.unassigned', ['2026-12-10' => 0, '2026-12-11' => 0, '2026-12-12' => 0]));
    foreach ([$t->a->otherProperty, $t->b->property] as $property) {
        $ids = inTenant($property, fn () => RoomType::query()->pluck('id')->all());
        $this->get(route('rate-calendar', ['from' => '2026-12-10', 'to' => '2026-12-12', 'room_type_ids' => $ids]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('roomTypes', 0));
    }
});
