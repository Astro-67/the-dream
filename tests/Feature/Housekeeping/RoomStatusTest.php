<?php

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use Tests\Support\InventoryForm;

test('new rooms start dirty without changing their inventory attributes', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType());
        $room = app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id));
        $status = RoomStatus::query()->where('room_id', $room->id)->sole();
        expect($status->status)->toBe(HousekeepingStatus::Dirty)
            ->and($status->property_id)->toBe($room->property_id)
            ->and($room->fresh()->only(['number', 'floor', 'room_type_id']))
            ->toBe(['number' => '101', 'floor' => '1', 'room_type_id' => $type->id]);
    });
});
