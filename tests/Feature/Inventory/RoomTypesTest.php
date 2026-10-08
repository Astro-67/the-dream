<?php

use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
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
