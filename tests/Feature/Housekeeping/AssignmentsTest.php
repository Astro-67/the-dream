<?php

use App\Domains\Guests\Models\Guest;
use App\Domains\Housekeeping\Actions\AssignRoom;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;

test('housekeepers see only their own rooms today and complete cleaning once with history', function () {
    $this->travelTo(Carbon::parse('2026-10-11 22:30:00', 'UTC'));
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

test('assignment permissions match each role and housekeepers cannot operate another list', function (OrganizationRole $role) {
    $t = twoTenants();
    $room = inTenant($t->a->property, function () use ($t) {
        $type = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());

        return app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($type->id));
    });
    $this->actingAs($t->a->member($role));
    $supervisor = in_array($role, [OrganizationRole::Owner, OrganizationRole::GroupOperationsManager, OrganizationRole::PropertyManager], true);
    $this->get('/housekeeping/assignments')->assertStatus($supervisor ? 200 : 403);
    $this->post('/housekeeping/assignments', ['_property' => $t->a->property->id, 'business_date' => '2026-10-12', 'housekeeper_id' => $t->a->member(OrganizationRole::Housekeeping)->id, 'room_id' => $room->id])->assertStatus($supervisor ? 302 : 403);
    $this->get('/housekeeping/my-rooms')->assertStatus($supervisor || $role === OrganizationRole::Housekeeping ? 200 : 403);
})->with(OrganizationRole::cases());

test('assignment recipients must have cleaning permission and access to the intended property', function () {
    $t = twoTenants();
    $room = inTenant($t->a->otherProperty, function () use ($t) {
        $type = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());

        return app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($type->id));
    });
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->otherProperty->id])->actingAs($t->a->owner);
    foreach ([$t->a->member(OrganizationRole::Housekeeping)->id, $t->a->member(OrganizationRole::FrontDesk)->id, $t->b->member(OrganizationRole::Housekeeping)->id, 'missing'] as $userId) {
        $this->postJson('/housekeeping/assignments', ['_property' => $t->a->otherProperty->id, 'business_date' => '2026-10-12', 'housekeeper_id' => $userId, 'room_id' => $room->id])->assertUnprocessable()->assertJsonValidationErrors('housekeeper_id');
    }
    $this->get('/housekeeping/assignments?date=2026-10-12')->assertInertia(fn (Assert $page) => $page->has('assignments', 0)->has('housekeepers', 2));
});

test('housekeepers cannot complete or remove other assignments or reach another property', function () {
    $t = twoTenants();
    $assignment = inTenant($t->a->property, function () use ($t) {
        $type = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());
        $room = app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($type->id));

        return app(AssignRoom::class)->handle($t->a->owner, $room, $t->a->owner->id, '2026-10-12');
    });
    $this->actingAs($t->a->member(OrganizationRole::Housekeeping));
    $this->get('/housekeeping/my-rooms?date=2026-10-12&housekeeper_id='.$t->a->owner->id)->assertInertia(fn (Assert $page) => $page->has('assignments', 0));
    $this->post('/housekeeping/assignments/'.$assignment->id.'/done', ['_property' => $t->a->property->id])->assertForbidden();
    $this->delete('/housekeeping/assignments/'.$assignment->id, ['_property' => $t->a->property->id])->assertForbidden();
    $this->post('/housekeeping/assignments/'.$assignment->id.'/done', ['_property' => $t->a->otherProperty->id])->assertForbidden();
    expect(inTenant($t->a->property, fn () => $assignment->fresh()->completed_at))->toBeNull();
});

test('assignment pages exclude guest identities and reservation financial data', function () {
    $t = twoTenants();
    $room = inTenant($t->a->property, function () use ($t) {
        $guest = Guest::query()->create(['name' => 'PRIVATE-GUEST']);
        $guest->documents()->create(['type' => 'passport', 'number' => 'SECRET-PASSPORT', 'nationality' => 'TZ', 'issuing_country' => 'TZ', 'expiry' => '2030-12-31']);
        app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
        $room = Room::query()->sole();
        app(AssignRoom::class)->handle($t->a->owner, $room, $t->a->member(OrganizationRole::Housekeeping)->id, '2026-10-12');

        return $room;
    });
    foreach ([['/housekeeping/my-rooms', $t->a->member(OrganizationRole::Housekeeping)], ['/housekeeping/assignments', $t->a->owner]] as [$url, $user]) {
        $this->actingAs($user)->get($url.'?date=2026-10-12')->assertOk()->assertDontSee('PRIVATE-GUEST')->assertDontSee('SECRET-PASSPORT')->assertDontSee('Travel Booker')
            ->assertInertia(fn (Assert $page) => $page->has('assignments', 1)->missing('guests')->missing('documents')->missing('folios')->missing('payments')->missing('priceLines')->missing('reservations')->where('assignments.0.room.id', $room->id));
    }
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
