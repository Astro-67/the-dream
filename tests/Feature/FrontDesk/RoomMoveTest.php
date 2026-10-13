<?php

use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Carbon\CarbonImmutable;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;
use Tests\Support\TenantFixture;

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam')));

/** A guest checked in to room 101 for 10-12 December, with clean room 102 of the same type free. */
function inHouse(TenantFixture $tenant): Reservation
{
    return inTenant($tenant->property, function () use ($tenant) {
        $details = BookingForm::details($tenant->owner);
        $room = Room::query()->sole();
        app(CreateRoom::class)->handle($tenant->owner, InventoryForm::room($details['room_type_id'], ['number' => '102']));
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        $reservation = app(CreateReservation::class)->handle($tenant->owner, [...$details, 'room_id' => $room->id]);
        $guest = app(CreateGuest::class)->handle($tenant->owner, ['name' => 'Staying Guest', 'documents' => [['type' => 'passport', 'number' => 'P1234567', 'nationality' => 'DE']]]);
        ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);

        return app(CheckIn::class)->handle($tenant->owner, $reservation);
    });
}

function roomNumbered(TenantFixture $tenant, string $number): Room
{
    return inTenant($tenant->property, fn () => Room::query()->where('number', $number)->sole());
}

function roomStatusOf(TenantFixture $tenant, Room $room): HousekeepingStatus
{
    return inTenant($tenant->property, fn () => RoomStatus::query()->where('room_id', $room->id)->sole()->status);
}

test('front desk moves an in-house guest to a ready room, keeping the price and recording one event', function () {
    $t = twoTenants();
    $reservation = inHouse($t->a);
    [$old, $new] = [roomNumbered($t->a, '101'), roomNumbered($t->a, '102')];
    $prices = inTenant($t->a->property, fn () => $reservation->priceLines()->orderBy('date')->get(['date', 'amount', 'currency'])->toArray());
    $actor = $t->a->member(OrganizationRole::FrontDesk);
    $this->actingAs($actor)->post(route('reservations.room-move', $reservation), ['_property' => $t->a->property->id, 'room_id' => $new->id])
        ->assertRedirect(route('reservations.show', $reservation))->assertSessionHasNoErrors();
    expect(roomStatusOf($t->a, $old))->toBe(HousekeepingStatus::Dirty)->and(roomStatusOf($t->a, $new))->toBe(HousekeepingStatus::Occupied);
    inTenant($t->a->property, function () use ($reservation, $new, $old, $actor, $prices) {
        expect($reservation->fresh()->room_id)->toBe($new->id)
            ->and($reservation->priceLines()->orderBy('date')->get(['date', 'amount', 'currency'])->toArray())->toBe($prices);
        $event = $reservation->lifecycleEvents()->where('type', 'room_moved')->sole();
        expect($event->actor_id)->toBe($actor->id)->and($event->snapshot['room_id'])->toBe($new->id)->and($event->snapshot['from_room_id'])->toBe($old->id);
    });
});
