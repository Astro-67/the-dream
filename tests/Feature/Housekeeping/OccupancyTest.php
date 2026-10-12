<?php

use App\Domains\Housekeeping\Actions\ClearRoomOccupied;
use App\Domains\Housekeeping\Actions\SetRoomOccupied;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\StatusChange;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use Tests\Support\InventoryForm;

test('internal occupancy actions record the actor and reason without writing Inventory', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType());
        $room = app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id));
        $before = $room->fresh()->getAttributes();
        expect(app(SetRoomOccupied::class)->handle($owner, $room, 'Check-in')->status)->toBe(HousekeepingStatus::Occupied);
        expect(app(ClearRoomOccupied::class)->handle($owner, $room, 'Check-out')->status)->toBe(HousekeepingStatus::Dirty);
        $changes = StatusChange::query()->orderBy('id')->get();
        expect($changes)->toHaveCount(2)
            ->and($changes[0]->from_status)->toBe(HousekeepingStatus::Dirty)
            ->and($changes[0]->to_status)->toBe(HousekeepingStatus::Occupied)
            ->and($changes[0]->actor_id)->toBe($owner->id)
            ->and($changes[0]->reason)->toBe('Check-in')
            ->and($changes[0]->changed_at)->not->toBeNull()
            ->and($changes[1]->actor_id)->toBe($owner->id)
            ->and($changes[1]->reason)->toBe('Check-out')
            ->and($changes[1]->from_status)->toBe(HousekeepingStatus::Occupied)
            ->and($changes[1]->to_status)->toBe(HousekeepingStatus::Dirty)
            ->and($room->fresh()->getAttributes())->toBe($before);
    });
});
