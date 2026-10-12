<?php

use App\Domains\Housekeeping\Actions\ChangeRoomStatus;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Housekeeping\Models\StatusChange;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Actions\TakeRoomOutOfService;
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
