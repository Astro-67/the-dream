<?php

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Housekeeping\RoomReadiness;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use Tests\Support\InventoryForm;

test('only clean rooms are ready without an override', function (HousekeepingStatus $status) {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner, $status) {
        $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType());
        $room = app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id));
        RoomStatus::query()->where('room_id', $room->id)->sole()->update(['status' => $status]);
        expect(app(RoomReadiness::class)->isReady($room))->toBe($status === HousekeepingStatus::Clean);
    });
})->with(HousekeepingStatus::cases());
