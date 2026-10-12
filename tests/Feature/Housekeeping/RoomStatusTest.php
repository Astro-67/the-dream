<?php

use App\Domains\Housekeeping\Actions\ChangeRoomStatus;
use App\Domains\Housekeeping\Models\StatusChange;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use Tests\Support\InventoryForm;
use App\Domains\Inventory\Models\Room;

test('existing rooms in different properties are backfilled dirty', function () {
    $t = twoTenants();
    $migration = require database_path('migrations/2026_10_21_000064_housekeeping_create_room_statuses.php');
    $migration->down();
    $rooms = [];
    foreach ([$t->a->property, $t->a->otherProperty, $t->b->property] as $property) {
        $rooms[] = [$property, inTenant($property, function () use ($t) {
            $type = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());
            return Room::query()->create(InventoryForm::room($type->id));
        })];
    }
    $migration->up();
    foreach ($rooms as [$property, $room]) {
        inTenant($property, function () use ($room) {
            expect(RoomStatus::query()->where('room_id', $room->id)->sole()->status)->toBe(HousekeepingStatus::Dirty);
        });
    }
});

test('cleaning and dirtying a room records actor time statuses and reason', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType());
        $room = app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id));
        $before = $room->fresh()->getAttributes();
        app(ChangeRoomStatus::class)->handle($owner, $room, HousekeepingStatus::Clean, 'Cleaning completed');
        app(ChangeRoomStatus::class)->handle($owner, $room, HousekeepingStatus::Dirty, 'Needs another clean');
        $changes = StatusChange::query()->where('room_id', $room->id)->orderBy('id')->get();
        expect($changes)->toHaveCount(2)
            ->and($changes[0]->from_status)->toBe(HousekeepingStatus::Dirty)
            ->and($changes[0]->to_status)->toBe(HousekeepingStatus::Clean)
            ->and($changes[0]->actor_id)->toBe($owner->id)
            ->and($changes[0]->changed_at)->not->toBeNull()
            ->and($changes[0]->reason)->toBe('Cleaning completed')
            ->and($changes[1]->from_status)->toBe(HousekeepingStatus::Clean)
            ->and($changes[1]->to_status)->toBe(HousekeepingStatus::Dirty)
            ->and(RoomStatus::query()->where('room_id', $room->id)->sole()->status)->toBe(HousekeepingStatus::Dirty)
            ->and($room->fresh()->getAttributes())->toBe($before);
    });
});

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
