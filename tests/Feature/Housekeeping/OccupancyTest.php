<?php

use App\Domains\Housekeeping\Actions\ClearRoomOccupied;
use App\Domains\Housekeeping\Actions\MarkStayoverService;
use App\Domains\Housekeeping\Actions\SetRoomOccupied;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Housekeeping\Models\StatusChange;
use App\Domains\Housekeeping\Models\StayoverService;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InventoryForm;

test('stayover service marking is idempotent for each explicit business date', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType());
        $room = app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id));
        app(SetRoomOccupied::class)->handle($owner, $room, 'Check-in');
        $first = app(MarkStayoverService::class)->handle($owner, $room, '2026-10-12');
        $again = app(MarkStayoverService::class)->handle(null, $room, '2026-10-12');
        app(MarkStayoverService::class)->handle(null, $room, '2026-10-13');
        expect($again->id)->toBe($first->id)
            ->and($first->actor_id)->toBe($owner->id)
            ->and($first->business_date->toDateString())->toBe('2026-10-12')
            ->and(StayoverService::query()->count())->toBe(2)
            ->and(StatusChange::query()->count())->toBe(1);
    });
});

test('occupancy actions refuse empty reasons without changing history', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType());
        $room = app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id));
        expect(fn () => app(SetRoomOccupied::class)->handle($owner, $room, ' '))->toThrow(ValidationException::class);
        expect(StatusChange::query()->count())->toBe(0);
    });
});

test('the occupied board shows service only for the property local operating day fallback', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 22:30:00 UTC'));
    $t = twoTenants();
    $t->a->property->update(['timezone' => 'Africa/Dar_es_Salaam']);
    $room = inTenant($t->a->property, function () use ($t) {
        $type = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());
        $room = app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($type->id));
        app(SetRoomOccupied::class)->handle($t->a->owner, $room, 'Check-in');
        app(MarkStayoverService::class)->handle(null, $room, '2026-10-13');

        return $room;
    });
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner);
    $this->get(route('housekeeping.index', ['status' => 'occupied']))->assertInertia(fn (AssertableInertia $page) => $page->has('rooms', 1)->where('rooms.0.status', 'occupied')->where('rooms.0.stayoverService', true)->where('businessDate', '2026-10-13'));
    $this->travelTo(CarbonImmutable::parse('2026-10-13 22:30:00 UTC'));
    $this->get(route('housekeeping.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('rooms.0.stayoverService', false));
    inTenant($t->a->property, fn () => app(ClearRoomOccupied::class)->handle($t->a->owner, $room, 'Check-out'));
    $this->travelTo(CarbonImmutable::parse('2026-10-12 22:30:00 UTC'));
    $this->get(route('housekeeping.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('rooms.0.stayoverService', false));
});

test('internal occupancy and service actions enforce their source statuses', function (HousekeepingStatus $from) {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner, $from) {
        $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType());
        $room = app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id));
        $status = RoomStatus::query()->sole();
        $status->update(['status' => $from]);
        if (in_array($from, [HousekeepingStatus::Clean, HousekeepingStatus::Dirty, HousekeepingStatus::InspectionRequired], true)) {
            expect(app(SetRoomOccupied::class)->handle($owner, $room, 'Check-in')->status)->toBe(HousekeepingStatus::Occupied);
        } else {
            expect(fn () => app(SetRoomOccupied::class)->handle($owner, $room, 'Check-in'))->toThrow(ValidationException::class);
            expect(StatusChange::query()->count())->toBe(0);
        }
        $status->refresh()->update(['status' => $from]);
        if ($from === HousekeepingStatus::Occupied) {
            app(MarkStayoverService::class)->handle(null, $room, '2026-10-12');
            expect(app(ClearRoomOccupied::class)->handle(null, $room, 'Check-out')->status)->toBe(HousekeepingStatus::Dirty);
        } else {
            $count = StatusChange::query()->count();
            expect(fn () => app(ClearRoomOccupied::class)->handle($owner, $room, 'Check-out'))->toThrow(ValidationException::class);
            expect(fn () => app(MarkStayoverService::class)->handle(null, $room, '2026-10-12'))->toThrow(ValidationException::class);
            expect(StatusChange::query()->count())->toBe($count)->and(StayoverService::query()->count())->toBe(0)->and($status->fresh()->status)->toBe($from);
        }
    });
})->with(HousekeepingStatus::cases());

test('staff cannot enter or leave occupied even with lifecycle input', function (OrganizationRole $role) {
    $t = twoTenants();
    $room = inTenant($t->a->property, function () use ($t) {
        $type = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());

        return app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($type->id));
    });
    $this->actingAs($t->a->member($role));
    $payload = ['_property' => $t->a->property->id, 'status' => 'occupied', 'reason' => 'Manual', 'occupancyLifecycle' => true];
    $this->patchJson(route('housekeeping.status', $room->id), $payload)->assertUnprocessable()->assertJsonValidationErrors('status');
    inTenant($t->a->property, fn () => app(SetRoomOccupied::class)->handle($t->a->owner, $room, 'Check-in'));
    foreach (HousekeepingStatus::cases() as $to) {
        $this->patchJson(route('housekeeping.status', $room->id), [...$payload, 'status' => $to->value])->assertUnprocessable()->assertJsonValidationErrors('status');
    }
    inTenant($t->a->property, function () {
        expect(RoomStatus::query()->sole()->status)->toBe(HousekeepingStatus::Occupied)->and(StatusChange::query()->count())->toBe(1);
    });
})->with([OrganizationRole::Owner, OrganizationRole::GroupOperationsManager, OrganizationRole::PropertyManager, OrganizationRole::Housekeeping]);

test('service marking refuses invalid business dates', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType());
        $room = app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id));
        app(SetRoomOccupied::class)->handle($owner, $room, 'Check-in');
        foreach (['2026-02-30', 'tomorrow', '2026-10-12 00:00:00', ''] as $date) {
            expect(fn () => app(MarkStayoverService::class)->handle(null, $room, $date))->toThrow(ValidationException::class);
        }
        expect(StayoverService::query()->count())->toBe(0);
    });
});

test('internal actions cannot mutate rooms outside the current property', function () {
    $t = twoTenants();
    foreach ([$t->a->otherProperty, $t->b->property] as $property) {
        $room = inTenant($property, function () use ($t) {
            $type = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());

            return app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($type->id));
        });
        inTenant($t->a->property, function () use ($t, $room) {
            expect(fn () => app(SetRoomOccupied::class)->handle($t->a->owner, $room, 'Attempt'))->toThrow(ModelNotFoundException::class);
            expect(fn () => app(ClearRoomOccupied::class)->handle($t->a->owner, $room, 'Attempt'))->toThrow(ModelNotFoundException::class);
            expect(fn () => app(MarkStayoverService::class)->handle(null, $room, '2026-10-12'))->toThrow(ModelNotFoundException::class);
        });
        inTenant($property, fn () => expect(RoomStatus::query()->sole()->status)->toBe(HousekeepingStatus::Dirty)->and(StatusChange::query()->count())->toBe(0)->and(StayoverService::query()->count())->toBe(0));
    }
});

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
