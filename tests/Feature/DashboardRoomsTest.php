<?php

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Tenancy\Enums\PropertyStatus;
use Inertia\Testing\AssertableInertia as Assert;
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
