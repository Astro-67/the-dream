<?php

use App\Domains\Housekeeping\Actions\OverrideRoomReady;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Housekeeping\Models\StatusChange;
use App\Domains\Housekeeping\RoomReadiness;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Actions\TakeRoomOutOfService;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;

test('a clean room becomes unready when its service period starts without reconciliation', function () {
    [$owner, , $property] = organizationWithOwner();
    $this->travelTo(new DateTimeImmutable('2026-10-12 10:00:00 UTC'));
    inTenant($property, function () use ($owner) {
        $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType());
        $room = app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id));
        RoomStatus::query()->sole()->update(['status' => HousekeepingStatus::Clean]);
        app(TakeRoomOutOfService::class)->handle($owner, $room, [
            'starts_on' => '2026-10-13', 'returns_on' => '2026-10-15', 'reason' => 'Repairs',
        ]);
        expect(app(RoomReadiness::class)->isReady($room))->toBeTrue();
        $this->travelTo(new DateTimeImmutable('2026-10-13 10:00:00 UTC'));
        expect(app(RoomReadiness::class)->isReady($room))->toBeFalse();
    });
});

test('only clean rooms are ready without an override', function (HousekeepingStatus $status) {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner, $status) {
        $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType());
        $room = app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id));
        RoomStatus::query()->where('room_id', $room->id)->sole()->update(['status' => $status]);
        expect(app(RoomReadiness::class)->isReady($room))->toBe($status === HousekeepingStatus::Clean);
    });
})->with(HousekeepingStatus::cases());

test('only owners and property managers can record readiness overrides with an audited reason', function (OrganizationRole $role) {
    $t = twoTenants();
    $room = inTenant($t->a->property, function () use ($t) {
        $type = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());

        return app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($type->id));
    });
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->member($role));
    $response = $this->post(route('housekeeping.override-ready', $room->id), ['_property' => $t->a->property->id, 'reason' => 'Guest waiting at the desk']);
    $allowed = in_array($role, [OrganizationRole::Owner, OrganizationRole::PropertyManager], true);
    $allowed ? $response->assertRedirect()->assertSessionHasNoErrors() : $response->assertForbidden();
    inTenant($t->a->property, function () use ($room, $allowed) {
        expect(app(RoomReadiness::class)->isReady($room))->toBe($allowed);
        expect(RoomStatus::query()->where('room_id', $room->id)->sole()->status)->toBe(HousekeepingStatus::Dirty);
        if ($allowed) {
            $audit = Activity::query()->where('event', 'ready_overridden')->sole();
            expect($audit->getExtraProperty('reason'))->toBe('Guest waiting at the desk');
        }
    });
    if ($allowed) {
        $this->get(route('housekeeping.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('rooms.0.isReady', true)->where('rooms.0.readyOverride.reason', 'Guest waiting at the desk')->where('canOverrideReady', true));
        $this->get(route('housekeeping.history', $room->id))->assertInertia(fn (AssertableInertia $page) => $page->where('changes.data.0.reason', 'Guest waiting at the desk')->where('changes.data.0.kind', 'ready_override')->where('changes.data.0.actor', $t->a->member($role)->name)->has('changes.data.0.businessDate'));
    }
})->with(OrganizationRole::cases());

test('room choices display current readiness without blocking assignment', function () {
    [$owner, , $property] = organizationWithOwner();
    [$details, $reservation] = inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);

        return [$details, app(CreateReservation::class)->handle($owner, $details)];
    });
    $this->actingAs($owner)->get(route('reservations.create', $details))->assertInertia(fn (AssertableInertia $page) => $page->where('rooms.0.isReady', false));
    $this->get(route('reservations.show', $reservation))->assertInertia(fn (AssertableInertia $page) => $page->where('rooms.0.isReady', false)->where('amendmentOptions.rooms.0.isReady', false));
});

test('overrides apply to one room and property local business date while history is retained', function () {
    $this->travelTo(now()->setTimezone('UTC')->setDate(2026, 10, 12)->setTime(20, 59));
    [$owner, , $property] = organizationWithOwner(['timezone' => 'Africa/Dar_es_Salaam']);
    [$room, $other] = inTenant($property, function () use ($owner) {
        $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType());

        return [app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id)), app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id, ['number' => '102']))];
    });
    $this->actingAs($owner)->post(route('housekeeping.override-ready', $room->id), ['_property' => $property->id, 'reason' => 'Emergency readiness'])->assertSessionHasNoErrors();
    inTenant($property, function () use ($room, $other) {
        expect(app(RoomReadiness::class)->isReady($room))->toBeTrue()->and(app(RoomReadiness::class)->isReady($other))->toBeFalse();
    });
    $this->travel(2)->minutes();
    inTenant($property, fn () => expect(app(RoomReadiness::class)->isReady($room))->toBeFalse());
    $this->get(route('housekeeping.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('rooms.0.readyOverride', null)->where('rooms.0.isReady', false));
    $this->get(route('housekeeping.history', $room->id))->assertInertia(fn (AssertableInertia $page) => $page->where('changes.data.0.businessDate', '2026-10-12')->where('changes.data.0.reason', 'Emergency readiness'));
});

test('readiness overrides require a nonblank bounded reason and ignore a supplied business date', function () {
    [$owner, , $property] = organizationWithOwner();
    $room = inTenant($property, function () use ($owner) {
        $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType());

        return app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id));
    });
    foreach ([null, '   ', str_repeat('x', 1001)] as $reason) {
        $this->actingAs($owner)->postJson(route('housekeeping.override-ready', $room->id), ['_property' => $property->id, 'reason' => $reason])->assertUnprocessable()->assertJsonValidationErrors('reason');
    }
    inTenant($property, fn () => expect(app(RoomReadiness::class)->isReady($room))->toBeFalse());
    $this->post(route('housekeeping.override-ready', $room->id), ['_property' => $property->id, 'reason' => 'Approved', 'business_date' => '2099-01-01'])->assertSessionHasNoErrors();
    $this->get(route('housekeeping.history', $room->id))->assertInertia(fn (AssertableInertia $page) => $page->has('changes.data', 1)->where('changes.data.0.businessDate', now($property->timezone)->toDateString()));
});

test('out of service rooms refuse audited readiness overrides and ignore existing overrides', function (bool $period) {
    [$owner, , $property] = organizationWithOwner();
    $room = inTenant($property, function () use ($owner, $period) {
        $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType());
        $room = app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id));
        app(OverrideRoomReady::class)->handle($owner, $room, 'Approved before maintenance');
        if ($period) {
            app(TakeRoomOutOfService::class)->handle($owner, $room, [
                'starts_on' => app(RoomReadiness::class)->businessDate($room),
                'returns_on' => now()->addDays(2)->toDateString(), 'reason' => 'Repairs',
            ]);
            RoomStatus::query()->sole()->update(['status' => HousekeepingStatus::Clean]);
        } else {
            RoomStatus::query()->sole()->update(['status' => HousekeepingStatus::Unavailable]);
        }

        return $room;
    });
    $this->actingAs($owner)->postJson(route('housekeeping.override-ready', $room), ['_property' => $property->id, 'reason' => 'Guest waiting'])
        ->assertUnprocessable()->assertJsonValidationErrors('reason');
    inTenant($property, function () use ($room) {
        expect(app(RoomReadiness::class)->isReady($room))->toBeFalse();
        expect(StatusChange::query()->where('kind', 'ready_override')->count())->toBe(1);
        expect(Activity::query()->where('event', 'refused')->sole()->getExtraProperty('result'))->toBe('refused');
    });
})->with([false, true]);

test('overrides refuse another property in the same organization', function () {
    $t = twoTenants();
    $room = inTenant($t->a->otherProperty, function () use ($t) {
        $type = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());

        return app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($type->id));
    });
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->member(OrganizationRole::PropertyManager));
    $this->post(route('housekeeping.override-ready', $room->id), ['_property' => $t->a->property->id, 'reason' => 'Attempt'])->assertNotFound();
    $this->post(route('housekeeping.override-ready', $room->id), ['_property' => $t->a->otherProperty->id, 'reason' => 'Attempt'])->assertForbidden();
    inTenant($t->a->otherProperty, fn () => expect(app(RoomReadiness::class)->isReady($room))->toBeFalse());
});
