<?php

use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Inventory\Models\Room;
use App\Domains\Rates\Actions\SetRatePrices;
use App\Domains\Rates\Actions\UpdateRatePlan;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
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
        $reservation = app(CreateReservation::class)->handle($tenant->owner, [...$details, 'adults' => 1, 'children' => 0, 'room_id' => $room->id]);
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

/** Posts a move and asserts it was refused on $field with the stay, rooms and inventory unchanged. */
function assertMoveRefused(TenantFixture $tenant, Reservation $reservation, string $roomId, string $field, string $pricing = 'keep'): void
{
    $state = fn () => inTenant($tenant->property, fn () => [
        RoomStatus::query()->orderBy('room_id')->pluck('status', 'room_id')->all(),
        InventoryRecord::query()->orderBy('room_type_id')->orderBy('date')->pluck('sold_count')->all(),
        $reservation->fresh()->only(['room_id', 'room_type_id']),
        $reservation->priceLines()->orderBy('date')->pluck('amount')->all(),
    ]);
    $before = $state();
    test()->actingAs($tenant->owner)->postJson(route('reservations.room-move', $reservation), ['_property' => $tenant->property->id, 'room_id' => $roomId, 'pricing' => $pricing])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($state())->toBe($before);
    inTenant($tenant->property, fn () => expect($reservation->lifecycleEvents()->where('type', 'room_moved')->exists())->toBeFalse());
}

/** Adds suite room 201, priced on the reservation's rate plan at 200.00 a night, and returns it. */
function suite(TenantFixture $tenant, Reservation $reservation): Room
{
    return inTenant($tenant->property, function () use ($tenant, $reservation) {
        $type = app(CreateRoomType::class)->handle($tenant->owner, InventoryForm::roomType(['name' => 'Suite', 'code' => 'STE']));
        $room = app(CreateRoom::class)->handle($tenant->owner, InventoryForm::room($type->id, ['number' => '201']));
        RoomStatus::query()->where('room_id', $room->id)->update(['status' => HousekeepingStatus::Clean]);
        $plan = RatePlan::query()->findOrFail($reservation->rate_plan_id);
        app(UpdateRatePlan::class)->handle($tenant->owner, $plan, ['name' => $plan->name, 'code' => $plan->code, 'room_type_ids' => [$reservation->room_type_id, $type->id]]);
        app(SetRatePrices::class)->handle($tenant->owner, $plan, ['room_type_id' => $type->id, 'from' => '2026-12-10', 'to' => '2026-12-11', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'base_price' => 20000, 'extra_adult' => 2500, 'extra_child' => 1000]);

        return $room;
    });
}

/** @return array<string, int> sold counts by date for the room type */
function soldOf(TenantFixture $tenant, string $roomTypeId): array
{
    return inTenant($tenant->property, fn () => InventoryRecord::query()->where('room_type_id', $roomTypeId)->whereDate('date', '<=', '2026-12-11')->orderBy('date')->get()
        ->mapWithKeys(fn (InventoryRecord $record) => [$record->date->toDateString() => $record->sold_count])->all());
}

test('a move to a room that is not ready is refused', function (HousekeepingStatus $status) {
    $t = twoTenants();
    $reservation = inHouse($t->a);
    $new = roomNumbered($t->a, '102');
    inTenant($t->a->property, fn () => RoomStatus::query()->where('room_id', $new->id)->update(['status' => $status]));
    assertMoveRefused($t->a, $reservation, $new->id, 'room_id');
})->with([HousekeepingStatus::Dirty, HousekeepingStatus::InspectionRequired, HousekeepingStatus::Occupied, HousekeepingStatus::Unavailable]);

test('only a checked-in guest can move room', function () {
    $t = twoTenants();
    $reservation = inHouse($t->a);
    inTenant($t->a->property, fn () => $reservation->update(['status' => ReservationStatus::Confirmed]));
    assertMoveRefused($t->a, $reservation, roomNumbered($t->a, '102')->id, 'reservation');
});

test('a move to the current room is refused', function () {
    $t = twoTenants();
    $reservation = inHouse($t->a);
    assertMoveRefused($t->a, $reservation, roomNumbered($t->a, '101')->id, 'room_id');
});

test('re-pricing replaces only the remaining nights from the rate plan', function () {
    $t = twoTenants();
    $reservation = inHouse($t->a);
    $t->a->property->update(['business_date' => '2026-12-11']);
    inTenant($t->a->property, function () use ($t, $reservation) {
        $plan = RatePlan::query()->findOrFail($reservation->rate_plan_id);
        app(SetRatePrices::class)->handle($t->a->owner, $plan, ['room_type_id' => $reservation->room_type_id, 'from' => '2026-12-10', 'to' => '2026-12-11', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'base_price' => 15000, 'extra_adult' => 2500, 'extra_child' => 1000]);
    });
    $this->actingAs($t->a->owner)->post(route('reservations.room-move', $reservation), ['_property' => $t->a->property->id, 'room_id' => roomNumbered($t->a, '102')->id, 'pricing' => 'reprice'])
        ->assertSessionHasNoErrors();
    inTenant($t->a->property, fn () => expect($reservation->priceLines()->orderBy('date')->pluck('amount')->all())->toBe([10000, 15000])
        ->and($reservation->lifecycleEvents()->where('type', 'room_moved')->sole()->snapshot['pricing'])->toBe('reprice'));
});

test('a move to another room type takes and releases inventory and keeps the price', function () {
    $t = twoTenants();
    $reservation = inHouse($t->a);
    $suite = suite($t->a, $reservation);
    $old = roomNumbered($t->a, '101');
    $this->actingAs($t->a->owner)->post(route('reservations.room-move', $reservation), ['_property' => $t->a->property->id, 'room_id' => $suite->id])
        ->assertSessionHasNoErrors();
    expect(soldOf($t->a, $old->room_type_id))->toBe(['2026-12-10' => 0, '2026-12-11' => 0])
        ->and(soldOf($t->a, $suite->room_type_id))->toBe(['2026-12-10' => 1, '2026-12-11' => 1])
        ->and(roomStatusOf($t->a, $old))->toBe(HousekeepingStatus::Dirty)->and(roomStatusOf($t->a, $suite))->toBe(HousekeepingStatus::Occupied);
    inTenant($t->a->property, fn () => expect($reservation->fresh()->only(['room_id', 'room_type_id']))->toBe(['room_id' => $suite->id, 'room_type_id' => $suite->room_type_id])
        ->and($reservation->priceLines()->orderBy('date')->pluck('amount')->all())->toBe([10000, 10000]));
});

test('a cross-type move can re-price on the new room type', function () {
    $t = twoTenants();
    $reservation = inHouse($t->a);
    $suite = suite($t->a, $reservation);
    $this->actingAs($t->a->owner)->post(route('reservations.room-move', $reservation), ['_property' => $t->a->property->id, 'room_id' => $suite->id, 'pricing' => 'reprice'])
        ->assertSessionHasNoErrors();
    inTenant($t->a->property, fn () => expect($reservation->priceLines()->orderBy('date')->pluck('amount')->all())->toBe([20000, 20000]));
});

test('a cross-type move is refused when the new room type has no inventory left', function () {
    $t = twoTenants();
    $reservation = inHouse($t->a);
    $suite = suite($t->a, $reservation);
    inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, [...$reservation->only(['rate_plan_id', 'booker_id', 'adults', 'children', 'source']), 'room_type_id' => $suite->room_type_id, 'arrival' => '2026-12-11', 'departure' => '2026-12-12']));
    assertMoveRefused($t->a, $reservation, $suite->id, 'room_type_id');
});

test('only reservation managers may move a guest', function (OrganizationRole $role, bool $allowed) {
    $t = twoTenants();
    $reservation = inHouse($t->a);
    $new = roomNumbered($t->a, '102');
    $this->actingAs($t->a->member($role))->post(route('reservations.room-move', $reservation), ['_property' => $t->a->property->id, 'room_id' => $new->id])->assertStatus($allowed ? 302 : 403);
    inTenant($t->a->property, fn () => expect($reservation->fresh()->room_id)->toBe($allowed ? $new->id : roomNumbered($t->a, '101')->id));
})->with(array_map(fn ($role) => [$role, in_array($role, [OrganizationRole::Owner, OrganizationRole::GroupOperationsManager, OrganizationRole::PropertyManager, OrganizationRole::FrontDesk], true)], OrganizationRole::cases()));

test('the reservation page offers in-house moves to free rooms of any type', function (OrganizationRole $role, bool $offered) {
    $t = twoTenants();
    $reservation = inHouse($t->a);
    $suite = suite($t->a, $reservation);
    inTenant($t->a->property, fn () => RoomStatus::query()->where('room_id', $suite->id)->update(['status' => HousekeepingStatus::Dirty]));
    $this->actingAs($t->a->member($role))->get(route('reservations.show', $reservation))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.moveRoom', $offered)
            ->where('moveRooms', $offered ? [
                ['id' => roomNumbered($t->a, '102')->id, 'number' => '102', 'roomType' => 'Deluxe Double', 'isReady' => true],
                ['id' => $suite->id, 'number' => '201', 'roomType' => 'Suite', 'isReady' => false],
            ] : []));
})->with([[OrganizationRole::FrontDesk, true], [OrganizationRole::Finance, false]]);

test('a room move requires the opening property intent', function () {
    $t = twoTenants();
    $reservation = inHouse($t->a);
    $this->actingAs($t->a->owner)->postJson(route('reservations.room-move', $reservation), ['room_id' => roomNumbered($t->a, '102')->id])->assertUnprocessable()->assertJsonValidationErrors('_property');
    inTenant($t->a->property, fn () => expect($reservation->fresh()->room_id)->toBe(roomNumbered($t->a, '101')->id));
});
