<?php

use App\Domains\Housekeeping\Actions\ChangeRoomStatus;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InventoryForm;

test('the housekeeping board lists room status type and floor for each permitted role', function (OrganizationRole $role) {
    $t = twoTenants();
    $room = inTenant($t->a->property, function () use ($t) {
        $type = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());
        return app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($type->id));
    });
    $response = $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->member($role))->get(route('housekeeping.index'));
    if ($role === OrganizationRole::Finance) {
        $response->assertForbidden();
        return;
    }
    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('housekeeping/index')
        ->has('rooms', 1)
        ->where('rooms.0.id', $room->id)
        ->where('rooms.0.number', '101')
        ->where('rooms.0.floor', '1')
        ->where('rooms.0.roomType.name', 'Deluxe Double')
        ->where('rooms.0.status', 'dirty')
        ->where('canManage', in_array($role, [OrganizationRole::Owner, OrganizationRole::GroupOperationsManager, OrganizationRole::PropertyManager, OrganizationRole::Housekeeping], true))
        ->where('canSupervise', in_array($role, [OrganizationRole::Owner, OrganizationRole::GroupOperationsManager, OrganizationRole::PropertyManager], true))
        ->missing('guests')->missing('identityDocuments')->missing('folios')->missing('priceLines')->missing('payments'));
})->with(OrganizationRole::cases());
