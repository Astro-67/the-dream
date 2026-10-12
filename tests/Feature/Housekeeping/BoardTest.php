<?php

use App\Domains\Housekeeping\Actions\ChangeRoomStatus;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InventoryForm;

test('the board filters status and floor without showing another property', function () {
    $t = twoTenants();
    inTenant($t->a->property, function () use ($t) {
        $type = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());
        $room = app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($type->id));
        app(ChangeRoomStatus::class)->handle($t->a->owner, $room, HousekeepingStatus::Clean, 'Done');
        app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($type->id, ['number' => '201', 'floor' => '2']));
        app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($type->id, ['number' => '001', 'floor' => null]));
    });
    inTenant($t->a->otherProperty, function () use ($t) {
        $type = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());
        app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($type->id, ['number' => 'SECRET']));
    });
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner);
    $this->get(route('housekeeping.index', ['status' => 'dirty', 'floor' => '2']))->assertInertia(fn (Assert $page) => $page->has('rooms', 1)->where('rooms.0.number', '201')->where('filters.status', 'dirty')->where('filters.floor', '2')->has('floors', 3));
    $this->get(route('housekeeping.index', ['status' => 'clean']))->assertInertia(fn (Assert $page) => $page->has('rooms', 1)->where('rooms.0.number', '101'));
    $this->get(route('housekeeping.index', ['floor' => '__none']))->assertInertia(fn (Assert $page) => $page->has('rooms', 1)->where('rooms.0.number', '001'));
    $this->get(route('housekeeping.index'))->assertInertia(fn (Assert $page) => $page->has('rooms', 3))->assertDontSee('SECRET');
    $this->getJson(route('housekeeping.index', ['status' => 'invented']))->assertUnprocessable();
});

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
