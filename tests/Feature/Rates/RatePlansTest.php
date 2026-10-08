<?php

use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InventoryForm;

test('a property manager creates a rate plan for a room type and sees it listed', function () {
    $t = twoTenants();
    $room = inTenant($t->a->property, fn () => app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType()));
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))
        ->post(route('rate-plans.store'), ['_property' => $t->a->property->id, 'name' => 'Bed and breakfast', 'code' => 'BB', 'currency' => 'USD', 'room_type_ids' => [$room->id]])
        ->assertRedirect(route('rate-plans.index'));
    $this->get(route('rate-plans.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('rates/index')->has('ratePlans', 1)->where('ratePlans.0.code', 'BB')->where('ratePlans.0.currency', 'USD')->where('canManage', true));
});
