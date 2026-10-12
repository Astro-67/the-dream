<?php

use App\Domains\Housekeeping\Actions\MarkStayoverService;
use App\Domains\Housekeeping\Models\StayoverService;
use App\Domains\Housekeeping\Actions\ClearRoomOccupied;
use App\Domains\Housekeeping\Actions\SetRoomOccupied;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\StatusChange;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
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
        expect(fn () => app(SetRoomOccupied::class)->handle($owner, $room, ' '))->toThrow(\Illuminate\Validation\ValidationException::class);
        expect(StatusChange::query()->count())->toBe(0);
    });
});

test('the occupied board shows service only for the property local operating day fallback', function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-12 22:30:00 UTC'));
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
    $this->get(route('housekeeping.index', ['status' => 'occupied']))->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->has('rooms', 1)->where('rooms.0.status', 'occupied')->where('rooms.0.stayoverService', true)->where('businessDate', '2026-10-13'));
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-13 22:30:00 UTC'));
    $this->get(route('housekeeping.index'))->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->where('rooms.0.stayoverService', false));
    inTenant($t->a->property, fn () => app(ClearRoomOccupied::class)->handle($t->a->owner, $room, 'Check-out'));
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-12 22:30:00 UTC'));
    $this->get(route('housekeeping.index'))->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->where('rooms.0.stayoverService', false));
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
