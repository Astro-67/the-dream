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

test('only owners and property managers can record readiness overrides with an audited reason', function (\App\Domains\Tenancy\Enums\OrganizationRole $role) {
    $t = twoTenants();
    $room = inTenant($t->a->property, function () use ($t) {
        $type = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());
        return app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($type->id));
    });
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->member($role));
    $response = $this->post(route('housekeeping.override-ready', $room->id), ['_property' => $t->a->property->id, 'reason' => 'Guest waiting at the desk']);
    $allowed = in_array($role, [\App\Domains\Tenancy\Enums\OrganizationRole::Owner, \App\Domains\Tenancy\Enums\OrganizationRole::PropertyManager], true);
    $allowed ? $response->assertRedirect()->assertSessionHasNoErrors() : $response->assertForbidden();
    inTenant($t->a->property, function () use ($room, $allowed) {
        expect(app(RoomReadiness::class)->isReady($room))->toBe($allowed);
        expect(RoomStatus::query()->where('room_id', $room->id)->sole()->status)->toBe(HousekeepingStatus::Dirty);
        if ($allowed) {
            $audit = \Spatie\Activitylog\Models\Activity::query()->where('event', 'ready_overridden')->sole();
            expect($audit->getExtraProperty('reason'))->toBe('Guest waiting at the desk');
        }
    });
    if ($allowed) {
        $this->get(route('housekeeping.index'))->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->where('rooms.0.isReady', true)->where('rooms.0.readyOverride.reason', 'Guest waiting at the desk')->where('canOverrideReady', true));
        $this->get(route('housekeeping.history', $room->id))->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->where('changes.data.0.reason', 'Guest waiting at the desk')->where('changes.data.0.kind', 'ready_override')->where('changes.data.0.actor', $t->a->member($role)->name)->has('changes.data.0.businessDate'));
    }
})->with(\App\Domains\Tenancy\Enums\OrganizationRole::cases());


test('room choices display current readiness without blocking assignment', function () {
    [$owner, , $property] = organizationWithOwner();
    [$details, $reservation] = inTenant($property, function () use ($owner) {
        $details = \Tests\Support\BookingForm::details($owner);
        return [$details, app(\App\Domains\Reservations\Actions\CreateReservation::class)->handle($owner, $details)];
    });
    $this->actingAs($owner)->get(route('reservations.create', $details))->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->where('rooms.0.isReady', false));
    $this->get(route('reservations.show', $reservation))->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->where('rooms.0.isReady', false)->where('amendmentOptions.rooms.0.isReady', false));
});
