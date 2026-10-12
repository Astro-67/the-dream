<?php

use App\Domains\Housekeeping\Actions\ChangeRoomStatus;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Housekeeping\Models\StatusChange;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Models\Room;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\InventoryForm;

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

test('every other status transition is refused without changing status or history', function (HousekeepingStatus $from, HousekeepingStatus $to) {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner, $from, $to) {
        $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType());
        $room = app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id));
        RoomStatus::query()->where('room_id', $room->id)->sole()->update(['status' => $from]);
        try {
            app(ChangeRoomStatus::class)->handle($owner, $room, $to, 'Attempt');
            test()->fail('Transition should be refused');
        } catch (ValidationException $exception) {
            expect($exception->errors()['status'][0])->toContain('not allowed');
        }
        expect(RoomStatus::query()->where('room_id', $room->id)->sole()->status)->toBe($from)
            ->and(StatusChange::query()->count())->toBe(0);
        expect(Activity::query()->where('description', 'Housekeeping status change refused')->sole()->getExtraProperty('result'))->toBe('refused');
    });
})->with(function () {
    foreach (HousekeepingStatus::cases() as $from) {
        foreach (HousekeepingStatus::cases() as $to) {
            if (($from === HousekeepingStatus::Dirty && $to === HousekeepingStatus::Clean) || ($from === HousekeepingStatus::Clean && $to === HousekeepingStatus::Dirty)) {
                continue;
            }
            yield $from->value.' to '.$to->value => [$from, $to];
        }
    }
});

test('history refuses updates and deletion', function (string $operation) {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner, $operation) {
        $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType());
        $room = app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id));
        app(ChangeRoomStatus::class)->handle($owner, $room, HousekeepingStatus::Clean, 'Done');
        $change = StatusChange::query()->sole();
        expect(fn () => $operation === 'update' ? $change->update(['reason' => 'Tampered']) : $change->delete())->toThrow(LogicException::class, 'append-only');
        expect($change->fresh()->reason)->toBe('Done');
    });
})->with(['update', 'delete']);

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
