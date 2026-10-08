<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Models\Room;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\InventoryForm;

function roomTypeIn(Property $property, User $actor, array $overrides = []): RoomType
{
    return inTenant($property, fn () => app(CreateRoomType::class)->handle($actor, InventoryForm::roomType($overrides)));
}

function roomIn(Property $property, User $actor, RoomType $roomType, array $overrides = []): Room
{
    return inTenant($property, fn () => app(CreateRoom::class)->handle($actor, InventoryForm::room($roomType->id, $overrides)));
}

test('a property manager adds a room to a room type and sees it listed', function () {
    $t = twoTenants();
    $deluxe = roomTypeIn($t->a->property, $t->a->owner);

    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))
        ->post(route('rooms.store'), InventoryForm::room($deluxe->id, ['_property' => $t->a->property->id]))
        ->assertRedirect(route('rooms.index'));

    $this->get(route('rooms.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('inventory/rooms/index')
            ->has('rooms', 1)
            ->where('rooms.0.number', '101')
            ->where('rooms.0.floor', '1')
            ->where('rooms.0.roomType.code', 'DLX')
            ->has('deactivatedRooms', 0)
            ->where('canManage', true));

    expect(Activity::query()->where('description', 'Room created')->sole()->getExtraProperty('attributes'))
        ->toEqual(['number' => '101', 'floor' => '1', 'room_type_id' => $deluxe->id]);
});

test('room numbers are unique within a property, so two properties can both have room 101', function () {
    $t = twoTenants();
    $here = roomTypeIn($t->a->property, $t->a->owner);
    $there = roomTypeIn($t->a->otherProperty, $t->a->owner);
    $this->actingAs($t->a->owner);

    $this->post(route('rooms.store'), InventoryForm::room($here->id, ['_property' => $t->a->property->id]))->assertSessionHasNoErrors();
    $this->post(route('rooms.store'), InventoryForm::room($here->id, ['_property' => $t->a->property->id]))->assertSessionHasErrors('number');
    $this->post(route('rooms.store'), InventoryForm::room($there->id, ['_property' => $t->a->otherProperty->id]))->assertSessionHasNoErrors();

    expect(inTenant($t->a->organization, fn () => Room::query()->where('number', '101')->count()))->toBe(2);
});

test('a room can only belong to a room type of its own property', function () {
    $t = twoTenants();
    $elsewhere = roomTypeIn($t->a->otherProperty, $t->a->owner);

    $this->actingAs($t->a->owner)
        ->post(route('rooms.store'), InventoryForm::room($elsewhere->id, ['_property' => $t->a->property->id]))
        ->assertSessionHasErrors('room_type_id');
});

test('a property manager edits a room\'s number and floor', function () {
    $t = twoTenants();
    $room = roomIn($t->a->property, $t->a->owner, roomTypeIn($t->a->property, $t->a->owner));

    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))
        ->get(route('rooms.edit', $room))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('inventory/rooms/edit')->where('room.number', '101')->has('roomTypes', 1));

    $this->put(route('rooms.update', $room), ['_property' => $t->a->property->id, 'number' => '102', 'floor' => '2'])
        ->assertRedirect(route('rooms.index'));

    $updated = Activity::query()->where('description', 'Room updated')->sole();
    expect($updated->getExtraProperty('old'))->toEqual(['number' => '101', 'floor' => '1'])
        ->and($updated->getExtraProperty('attributes'))->toEqual(['number' => '102', 'floor' => '2']);
});

test('a property manager moves a room to another room type, and the move is audited', function () {
    $t = twoTenants();
    $deluxe = roomTypeIn($t->a->property, $t->a->owner);
    $suite = roomTypeIn($t->a->property, $t->a->owner, ['code' => 'STE', 'name' => 'Suite']);
    $room = roomIn($t->a->property, $t->a->owner, $deluxe);

    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))
        ->put(route('rooms.move', $room), ['_property' => $t->a->property->id, 'room_type_id' => $suite->id])
        ->assertRedirect(route('rooms.index'));

    $this->get(route('rooms.index'))->assertInertia(fn (Assert $page) => $page->where('rooms.0.roomType.code', 'STE'));

    $moved = Activity::query()->where('event', 'moved')->sole();
    expect($moved->description)->toBe('Room moved to another room type')
        ->and($moved->getExtraProperty('old'))->toEqual(['room_type_id' => $deluxe->id])
        ->and($moved->getExtraProperty('attributes'))->toEqual(['room_type_id' => $suite->id]);
});

test('a deactivated room leaves the active list but stays visible, and deactivation is audited', function () {
    $t = twoTenants();
    $room = roomIn($t->a->property, $t->a->owner, roomTypeIn($t->a->property, $t->a->owner));

    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))
        ->post(route('rooms.deactivate', $room), ['_property' => $t->a->property->id])
        ->assertRedirect(route('rooms.index'));

    $this->get(route('rooms.index'))->assertInertia(fn (Assert $page) => $page
        ->has('rooms', 0)
        ->has('deactivatedRooms', 1)
        ->where('deactivatedRooms.0.number', '101'));
    $this->get(route('room-types.index'))->assertInertia(fn (Assert $page) => $page->where('roomTypes.0.activeRooms', 0));

    expect(Activity::query()->where('event', 'deactivated')->sole()->description)->toBe('Room deactivated');
});

test('a deactivated room can no longer be edited or moved', function () {
    $t = twoTenants();
    $roomType = roomTypeIn($t->a->property, $t->a->owner);
    $room = roomIn($t->a->property, $t->a->owner, $roomType);
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->post(route('rooms.deactivate', $room), ['_property' => $t->a->property->id]);

    $this->get(route('rooms.edit', $room))->assertForbidden();
    $this->put(route('rooms.update', $room), ['_property' => $t->a->property->id, 'number' => '999'])->assertForbidden();
    $this->post(route('rooms.deactivate', $room), ['_property' => $t->a->property->id])->assertForbidden();
});

test('front desk and auditors see rooms but cannot change them', function (OrganizationRole $role) {
    $t = twoTenants();
    $deluxe = roomTypeIn($t->a->property, $t->a->owner);
    $suite = roomTypeIn($t->a->property, $t->a->owner, ['code' => 'STE']);
    $room = roomIn($t->a->property, $t->a->owner, $deluxe);
    $p = ['_property' => $t->a->property->id];

    $this->actingAs($t->a->member($role))->get(route('rooms.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('rooms', 1)->where('canManage', false));
    $this->get(route('rooms.create'))->assertForbidden();
    $this->get(route('rooms.edit', $room))->assertForbidden();
    $this->post(route('rooms.store'), InventoryForm::room($deluxe->id, [...$p, 'number' => '202']))->assertForbidden();
    $this->put(route('rooms.update', $room), [...$p, 'number' => '999'])->assertForbidden();
    $this->put(route('rooms.move', $room), [...$p, 'room_type_id' => $suite->id])->assertForbidden();
    $this->post(route('rooms.deactivate', $room), $p)->assertForbidden();

    $after = inTenant($t->a->property, fn () => Room::query()->sole());
    expect($after->only(['number', 'room_type_id', 'deactivated_at']))->toBe(['number' => '101', 'room_type_id' => $deluxe->id, 'deactivated_at' => null]);
})->with([OrganizationRole::FrontDesk, OrganizationRole::Auditor]);
