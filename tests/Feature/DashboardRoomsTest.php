<?php

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\PropertyStatus;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;

test('dashboard rooms reflect housekeeping readiness rather than just vacancy', function (HousekeepingStatus $status, string $expected, string $label) {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['status' => PropertyStatus::Live, 'business_date' => '2026-12-10']);
    $room = inTenant($property, function () use ($owner, $status) {
        $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType());
        $room = app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id));
        RoomStatus::query()->where('room_id', $room->id)->update(['status' => $status]);

        return $room;
    });

    $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('roomBoard.businessDate', '2026-12-10')
        ->has('roomBoard.rooms.data', 1)
        ->where('roomBoard.rooms.data.0.id', $room->id)
        ->where('roomBoard.rooms.data.0.number', '101')
        ->where('roomBoard.rooms.data.0.roomType', 'Deluxe Double')
        ->where('roomBoard.rooms.data.0.status', $expected)
        ->where('roomBoard.rooms.data.0.statusLabel', $label));
})->with([
    [HousekeepingStatus::Dirty, 'dirty', 'Dirty'],
    [HousekeepingStatus::Clean, 'available', 'Available'],
    [HousekeepingStatus::InspectionRequired, 'inspection_required', 'Inspection required'],
    [HousekeepingStatus::Occupied, 'occupied', 'Occupied'],
    [HousekeepingStatus::Unavailable, 'out_of_order', 'Out of order'],
]);

test('dashboard room status combines assigned stays with effective service periods', function (string $stayStatus, string $arrival, string $departure, ?string $serviceFrom, ?string $serviceTo, HousekeepingStatus $housekeeping, string $expected, ?string $readiness) {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['status' => PropertyStatus::Live, 'business_date' => '2026-12-10']);
    inTenant($property, function () use ($owner, $stayStatus, $arrival, $departure, $serviceFrom, $serviceTo, $housekeeping) {
        $reservation = app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
        $room = Room::query()->sole();
        $reservation->update(['room_id' => $stayStatus === 'unassigned' ? null : $room->id, 'status' => $stayStatus === 'unassigned' ? 'confirmed' : $stayStatus, 'arrival' => $arrival, 'departure' => $departure]);
        RoomStatus::query()->sole()->update(['status' => $housekeeping]);
        if ($serviceFrom !== null) {
            $room->outOfServicePeriods()->create(['starts_on' => $serviceFrom, 'returns_on' => $serviceTo, 'reason' => 'Repairs']);
        }
    });

    $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('roomBoard.rooms.data.0.status', $expected)
        ->where('roomBoard.rooms.data.0.readinessLabel', $readiness));
})->with([
    'reserved needs cleaning' => ['confirmed', '2026-12-10', '2026-12-12', null, null, HousekeepingStatus::Dirty, 'reserved', 'Dirty'],
    'reserved needs inspection' => ['confirmed', '2026-12-10', '2026-12-12', null, null, HousekeepingStatus::InspectionRequired, 'reserved', 'Inspection required'],
    'reserved clean' => ['confirmed', '2026-12-10', '2026-12-12', null, null, HousekeepingStatus::Clean, 'reserved', null],
    'occupied before reserved' => ['checked_in', '2026-12-10', '2026-12-12', null, null, HousekeepingStatus::Clean, 'occupied', null],
    'overstay' => ['checked_in', '2026-12-01', '2026-12-09', null, null, HousekeepingStatus::Dirty, 'occupied', null],
    'future booking' => ['confirmed', '2026-12-11', '2026-12-12', null, null, HousekeepingStatus::Clean, 'available', null],
    'unassigned booking' => ['unassigned', '2026-12-10', '2026-12-12', null, null, HousekeepingStatus::Clean, 'available', null],
    'cancelled booking' => ['cancelled', '2026-12-10', '2026-12-12', null, null, HousekeepingStatus::Clean, 'available', null],
    'effective period beats occupancy' => ['checked_in', '2026-12-01', '2026-12-09', '2026-12-10', '2026-12-11', HousekeepingStatus::Occupied, 'out_of_order', null],
    'effective period beats reserved' => ['confirmed', '2026-12-10', '2026-12-12', '2026-12-09', '2026-12-11', HousekeepingStatus::Dirty, 'out_of_order', null],
    'future period' => ['cancelled', '2026-12-10', '2026-12-12', '2026-12-11', '2026-12-12', HousekeepingStatus::Clean, 'available', null],
    'period has ended' => ['cancelled', '2026-12-10', '2026-12-12', '2026-12-09', '2026-12-10', HousekeepingStatus::Dirty, 'dirty', null],
]);

test('same day turnover stays occupied until checkout then shows the assigned arrival and cleaning', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['status' => PropertyStatus::Live, 'business_date' => '2026-12-10']);
    $departing = inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        app(CreateRoom::class)->handle($owner, InventoryForm::room($details['room_type_id'], ['number' => '102']));
        $departing = app(CreateReservation::class)->handle($owner, [...$details, 'arrival' => '2026-12-10', 'departure' => '2026-12-11']);
        $departing->update(['status' => 'checked_in', 'arrival' => '2026-12-09', 'departure' => '2026-12-10', 'room_id' => Room::query()->where('number', '101')->sole()->id]);
        $arriving = app(CreateReservation::class)->handle($owner, $details);
        $arriving->update(['room_id' => $departing->room_id]);
        RoomStatus::query()->where('room_id', $departing->room_id)->update(['status' => HousekeepingStatus::Occupied]);

        return $departing;
    });
    $this->actingAs($owner)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('roomBoard.rooms.data.0.status', 'occupied'));
    inTenant($property, function () use ($departing) {
        $departing->update(['status' => 'checked_out']);
        RoomStatus::query()->where('room_id', $departing->room_id)->update(['status' => HousekeepingStatus::Dirty]);
    });
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('roomBoard.rooms.data.0.status', 'reserved')
        ->where('roomBoard.rooms.data.0.readinessLabel', 'Dirty'));
});
