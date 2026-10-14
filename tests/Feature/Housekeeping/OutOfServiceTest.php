<?php

use App\Domains\Housekeeping\Actions\ChangeRoomStatus;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Housekeeping\Models\StatusChange;
use App\Domains\Inventory\Actions\ChangeOutOfServicePeriod;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Actions\TakeRoomOutOfService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InventoryForm;

beforeEach(function () {
    $this->travelTo(new DateTimeImmutable('2026-10-12 22:00:00 UTC'));
    config(['inventory.horizon_years' => 1]);
});

test('taking a room out of service updates the board and records the actor and reason', function () {
    $t = twoTenants();
    inTenant($t->a->property, function () use ($t) {
        $type = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());
        $room = app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($type->id));
        app(ChangeRoomStatus::class)->handle($t->a->owner, $room, HousekeepingStatus::Clean, 'Cleaned');
        app(TakeRoomOutOfService::class)->handle($t->a->owner, $room, ['starts_on' => '2026-10-13', 'returns_on' => '2026-10-15', 'reason' => 'Leaking shower']);
        expect(RoomStatus::query()->sole()->status)->toBe(HousekeepingStatus::Unavailable);
        $change = StatusChange::query()->where('to_status', 'unavailable')->sole();
        expect($change->from_status)->toBe(HousekeepingStatus::Clean)
            ->and($change->actor_id)->toBe($t->a->owner->id)
            ->and($change->reason)->toBe('Leaking shower');
    });
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)
        ->get(route('housekeeping.index'))->assertInertia(fn (Assert $page) => $page->where('rooms.0.status', 'unavailable'));
});

test('changing and shortening periods reconciles status without duplicate history', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType());
        $room = app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id));
        $period = app(TakeRoomOutOfService::class)->handle($owner, $room, ['starts_on' => '2026-10-14', 'returns_on' => '2026-10-16', 'reason' => 'Repair']);
        expect(RoomStatus::query()->sole()->status)->toBe(HousekeepingStatus::Dirty)->and(StatusChange::query()->count())->toBe(0);
        $action = app(ChangeOutOfServicePeriod::class);
        $active = ['starts_on' => '2026-10-12', 'returns_on' => '2026-10-16', 'reason' => 'Repair'];
        $action->handle($owner, $period, $active);
        expect(RoomStatus::query()->sole()->status)->toBe(HousekeepingStatus::Unavailable);
        $action->handle($owner, $period, $active);
        $action->handle($owner, $period, [...$active, 'reason' => 'Plumbing repair']);
        expect(StatusChange::query()->count())->toBe(1);
        $action->handle($owner, $period, [...$active, 'returns_on' => '2026-10-13']);
        expect(RoomStatus::query()->sole()->status)->toBe(HousekeepingStatus::Dirty);
        $change = StatusChange::query()->where('to_status', 'dirty')->sole();
        expect($change->from_status)->toBe(HousekeepingStatus::Unavailable)->and($change->actor_id)->toBe($owner->id)->and($change->reason)->toBe('Room returned to service.');
        expect(StatusChange::query()->count())->toBe(2);
    });
});

test('automatic rollover follows each property business date and is idempotent', function () {
    $t = twoTenants();
    $dates = fn (string $a, string $other, string $b) => [$t->a->property->update(['business_date' => $a]), $t->a->otherProperty->update(['business_date' => $other]), $t->b->property->update(['business_date' => $b])];
    $dates('2026-10-12', '2026-10-11', '2026-10-12');
    foreach ([$t->a->property, $t->a->otherProperty, $t->b->property] as $property) {
        inTenant($property, function () {
            $type = app(CreateRoomType::class)->handle(null, InventoryForm::roomType());
            $room = app(CreateRoom::class)->handle(null, InventoryForm::room($type->id));
            app(TakeRoomOutOfService::class)->handle(null, $room, ['starts_on' => '2026-10-13', 'returns_on' => '2026-10-14', 'reason' => 'Repair']);
            expect(RoomStatus::query()->sole()->status)->toBe(HousekeepingStatus::Dirty);
        });
    }
    $dates('2026-10-13', '2026-10-12', '2026-10-13');
    $this->artisan('housekeeping:sync-service')->assertSuccessful();
    $this->artisan('housekeeping:sync-service')->assertSuccessful();
    foreach ([$t->a->property, $t->b->property] as $property) {
        inTenant($property, function () {
            expect(RoomStatus::query()->sole()->status)->toBe(HousekeepingStatus::Unavailable)
                ->and(StatusChange::query()->count())->toBe(1)->and(StatusChange::query()->sole()->actor_id)->toBeNull();
        });
    }
    expect(inTenant($t->a->otherProperty, fn () => RoomStatus::query()->sole()->status))->toBe(HousekeepingStatus::Dirty);
    $dates('2026-10-14', '2026-10-13', '2026-10-14');
    $this->artisan('housekeeping:sync-service')->assertSuccessful();
    $this->artisan('housekeeping:sync-service')->assertSuccessful();
    inTenant($t->a->property, function () {
        expect(RoomStatus::query()->sole()->status)->toBe(HousekeepingStatus::Dirty)->and(StatusChange::query()->count())->toBe(2);
    });
    expect(inTenant($t->a->otherProperty, fn () => RoomStatus::query()->sole()->status))->toBe(HousekeepingStatus::Unavailable);
});

test('manual cleaning is refused as soon as a period starts even before the scheduled sweep', function () {
    $t = twoTenants();
    $this->travelTo(new DateTimeImmutable('2026-10-12 20:59:00 UTC'));
    $room = inTenant($t->a->property, function () {
        $type = app(CreateRoomType::class)->handle(null, InventoryForm::roomType());
        $room = app(CreateRoom::class)->handle(null, InventoryForm::room($type->id));
        app(TakeRoomOutOfService::class)->handle(null, $room, ['starts_on' => '2026-10-13', 'returns_on' => '2026-10-14', 'reason' => 'Repair']);

        return $room;
    });
    $this->travelTo(new DateTimeImmutable('2026-10-12 21:00:00 UTC'));
    $this->actingAs($t->a->owner)->patchJson(route('housekeeping.status', $room->id), ['_property' => $t->a->property->id, 'status' => 'clean', 'reason' => 'Done'])->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->get(route('housekeeping.index'))->assertInertia(fn (Assert $page) => $page->where('rooms.0.status', 'unavailable'));
    foreach (['dirty', 'clean', 'occupied', 'inspection_required'] as $status) {
        $this->patchJson(route('housekeeping.status', $room->id), ['_property' => $t->a->property->id, 'status' => $status, 'reason' => 'Attempt'])->assertUnprocessable()->assertJsonValidationErrors('status');
    }
    inTenant($t->a->property, function () {
        expect(RoomStatus::query()->sole()->status)->toBe(HousekeepingStatus::Unavailable)->and(StatusChange::query()->count())->toBe(1);
    });
});

test('out of service supersedes every housekeeping status transactionally', function (HousekeepingStatus $from) {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner, $from) {
        $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType());
        $room = app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id));
        RoomStatus::query()->sole()->update(['status' => $from]);
        $before = $room->fresh()->getAttributes();
        app(TakeRoomOutOfService::class)->handle($owner, $room, ['starts_on' => '2026-10-13', 'returns_on' => '2026-10-14', 'reason' => 'Repair']);
        expect(RoomStatus::query()->sole()->status)->toBe(HousekeepingStatus::Unavailable)
            ->and($room->fresh()->getAttributes())->toBe($before);
        if ($from === HousekeepingStatus::Unavailable) {
            expect(StatusChange::query()->count())->toBe(0);
        } else {
            expect(StatusChange::query()->sole()->from_status)->toBe($from);
        }
    });
})->with(HousekeepingStatus::cases());

test('rolling back service creation also rolls back housekeeping status and history', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType());
        $room = app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id));
        try {
            DB::transaction(function () use ($owner, $room) {
                app(TakeRoomOutOfService::class)->handle($owner, $room, ['starts_on' => '2026-10-13', 'returns_on' => '2026-10-14', 'reason' => 'Repair']);
                throw new RuntimeException('Abort outer transaction');
            });
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toBe('Abort outer transaction');
        }
        expect(RoomStatus::query()->sole()->status)->toBe(HousekeepingStatus::Dirty)
            ->and(StatusChange::query()->count())->toBe(0)->and($room->outOfServicePeriods()->count())->toBe(0);
    });
});
