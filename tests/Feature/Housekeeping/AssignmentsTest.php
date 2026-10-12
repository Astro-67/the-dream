<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InventoryForm;

test('supervisors assign and unassign a room for a business date without duplicating it', function () {
    $t = twoTenants();
    $room = inTenant($t->a->property, function () use ($t) {
        $type = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());
        return app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($type->id));
    });
    $housekeeper = $t->a->member(OrganizationRole::Housekeeping);
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner);
    $payload = ['_property' => $t->a->property->id, 'business_date' => '2026-10-12', 'housekeeper_id' => $housekeeper->id, 'room_id' => $room->id];
    $this->post('/housekeeping/assignments', $payload)->assertRedirect()->assertSessionHasNoErrors();
    $this->post('/housekeeping/assignments', $payload)->assertRedirect()->assertSessionHasNoErrors();
    $response = $this->get('/housekeeping/assignments?date=2026-10-12')->assertInertia(fn (Assert $page) => $page->has('assignments', 1)->where('assignments.0.room.number', '101')->where('assignments.0.housekeeper.id', $housekeeper->id));
    $id = $response->inertiaProps('assignments.0.id');
    $this->delete('/housekeeping/assignments/'.$id, ['_property' => $t->a->property->id])->assertRedirect()->assertSessionHasNoErrors();
    $this->get('/housekeeping/assignments?date=2026-10-12')->assertInertia(fn (Assert $page) => $page->has('assignments', 0));
});
