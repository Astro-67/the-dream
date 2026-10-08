<?php

use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\InventoryForm;

test('a property manager creates a room type and sees it listed', function () {
    $t = twoTenants();

    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))
        ->post(route('room-types.store'), InventoryForm::roomType(['_property' => $t->a->property->id]))
        ->assertRedirect(route('room-types.index'));

    $this->get(route('room-types.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('inventory/room-types/index')
            ->has('roomTypes', 1)
            ->where('roomTypes.0.name', 'Deluxe Double')
            ->where('roomTypes.0.code', 'DLX')
            ->where('roomTypes.0.baseOccupancy', 2)
            ->where('roomTypes.0.maxOccupancy', 3)
            ->where('canManage', true));
});

test('room type codes are unique within a property, not across properties', function () {
    $t = twoTenants();
    $this->actingAs($t->a->owner);

    $this->post(route('room-types.store'), InventoryForm::roomType(['_property' => $t->a->property->id]))->assertSessionHasNoErrors();
    $this->post(route('room-types.store'), InventoryForm::roomType(['_property' => $t->a->property->id, 'name' => 'Other']))->assertSessionHasErrors('code');
    $this->post(route('room-types.store'), InventoryForm::roomType(['_property' => $t->a->otherProperty->id]))->assertSessionHasNoErrors();

    expect(inTenant($t->a->organization, fn () => RoomType::query()->where('code', 'DLX')->count()))->toBe(2);
});

test('occupancy limits must fit within the maximum guests', function () {
    $t = twoTenants();

    $this->actingAs($t->a->owner)
        ->post(route('room-types.store'), InventoryForm::roomType(['_property' => $t->a->property->id, 'base_occupancy' => 4, 'max_adults' => 4]))
        ->assertSessionHasErrors(['base_occupancy', 'max_adults']);
});

test('a room type edit is audited with the changed fields before and after', function () {
    $t = twoTenants();
    $roomType = inTenant($t->a->property, fn () => app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType()));
    $manager = $t->a->member(OrganizationRole::PropertyManager);

    $this->actingAs($manager)
        ->get(route('room-types.edit', $roomType))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('inventory/room-types/edit')->where('roomType.code', 'DLX'));

    $this->put(route('room-types.update', $roomType), InventoryForm::roomType(['_property' => $t->a->property->id, 'name' => 'Deluxe King']))
        ->assertRedirect(route('room-types.index'));

    $created = Activity::query()->where('description', 'Room type created')->sole();
    $updated = Activity::query()->where('description', 'Room type updated')->sole();
    expect($created->getExtraProperty('property_id'))->toBe($t->a->property->id)
        ->and($created->getExtraProperty('attributes')['code'])->toBe('DLX')
        ->and($updated->causer?->is($manager))->toBeTrue()
        ->and($updated->getExtraProperty('old'))->toEqual(['name' => 'Deluxe Double'])
        ->and($updated->getExtraProperty('attributes'))->toEqual(['name' => 'Deluxe King']);
});

test('front desk and auditors see room types but cannot change them', function (OrganizationRole $role) {
    $t = twoTenants();
    $roomType = inTenant($t->a->property, fn () => app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType()));

    $this->actingAs($t->a->member($role))->get(route('room-types.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('roomTypes', 1)->where('canManage', false));
    $this->get(route('room-types.create'))->assertForbidden();
    $this->get(route('room-types.edit', $roomType))->assertForbidden();
    $this->post(route('room-types.store'), InventoryForm::roomType(['_property' => $t->a->property->id, 'code' => 'NEW']))->assertForbidden();
    $this->put(route('room-types.update', $roomType), InventoryForm::roomType(['_property' => $t->a->property->id, 'name' => 'Changed']))->assertForbidden();

    expect(inTenant($t->a->property, fn () => RoomType::query()->pluck('name')->all()))->toBe(['Deluxe Double']);
})->with([OrganizationRole::FrontDesk, OrganizationRole::Auditor]);

test('housekeeping cannot see room types', function () {
    $t = twoTenants();

    $this->actingAs($t->a->member(OrganizationRole::Housekeeping))->get(route('room-types.index'))->assertForbidden();
});
