<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InventoryForm;

test('housekeepers see only their own rooms today and complete cleaning once with history', function () {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-11 22:30:00', 'UTC'));
    $t = twoTenants();
    $housekeeper = $t->a->member(OrganizationRole::Housekeeping);
    $room = inTenant($t->a->property, function () use ($t) {
        $type = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());
        return app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($type->id));
    });
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner);
    foreach ([[$housekeeper->id, '2026-10-12'], [$t->a->owner->id, '2026-10-12'], [$housekeeper->id, '2026-10-13']] as [$userId, $date]) {
        $this->post('/housekeeping/assignments', ['_property' => $t->a->property->id, 'business_date' => $date, 'housekeeper_id' => $userId, 'room_id' => $room->id])->assertRedirect()->assertSessionHasNoErrors();
    }
    $this->actingAs($housekeeper);
    $response = $this->get('/housekeeping/my-rooms')->assertInertia(fn (Assert $page) => $page->where('date', '2026-10-12')->has('assignments', 1)->where('assignments.0.completed', false)->missing('housekeepers')->missing('rooms')->missing('guests')->missing('identityDocuments')->missing('folios')->missing('priceLines'));
    $id = $response->inertiaProps('assignments.0.id');
    $this->post('/housekeeping/assignments/'.$id.'/done', ['_property' => $t->a->property->id])->assertRedirect()->assertSessionHasNoErrors();
    $this->post('/housekeeping/assignments/'.$id.'/done', ['_property' => $t->a->property->id])->assertRedirect()->assertSessionHasNoErrors();
    $this->get('/housekeeping/my-rooms')->assertInertia(fn (Assert $page) => $page->has('assignments', 1)->where('assignments.0.completed', true));
    $this->get(route('housekeeping.history', $room))->assertInertia(fn (Assert $page) => $page->has('changes.data', 1)->where('changes.data.0.actor', $housekeeper->name)->where('changes.data.0.from', 'Dirty')->where('changes.data.0.to', 'Clean')->where('changes.data.0.reason', 'Cleaning completed'));
});

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
