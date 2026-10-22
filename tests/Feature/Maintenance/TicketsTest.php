<?php

use App\Domains\Billing\Models\Folio;
use App\Domains\Guests\Models\Guest;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Inventory\Models\OutOfServicePeriod;
use App\Domains\Inventory\Models\Room;
use App\Domains\Maintenance\Actions\AssignTicket;
use App\Domains\Maintenance\Actions\CompleteTicket;
use App\Domains\Maintenance\Actions\ReportTicket;
use App\Domains\Maintenance\Models\Ticket;
use App\Domains\Maintenance\Models\TicketEvent;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Actions\RemoveMember;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\OrganizationMembership;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;

function maintenanceRoom(Property $property, User $owner, string $number = '101'): Room
{
    return inTenant($property, function () use ($owner, $number) {
        $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType(['code' => 'M'.$number]));

        return app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id, ['number' => $number]));
    });
}

/** @return array<string, mixed> */
function maintenanceReport(Property $property, array $overrides = []): array
{
    return ['_property' => $property->id, 'title' => 'Leaking tap', 'description' => 'Bathroom tap drips constantly.', 'category' => 'repair', 'priority' => 'high', 'room_id' => null, 'location' => null, ...$overrides];
}

test('a housekeeper reports a problem for a room and sees it on the open board', function () {
    $t = twoTenants();
    $room = maintenanceRoom($t->a->property, $t->a->owner);
    $housekeeper = $t->a->member(OrganizationRole::Housekeeping);

    $this->actingAs($housekeeper)->post(route('maintenance.store'), maintenanceReport($t->a->property, ['room_id' => $room->id]))->assertRedirect()->assertSessionHasNoErrors();

    $this->get(route('maintenance.index', ['room' => $room->id]))->assertInertia(fn (Assert $page) => $page->component('maintenance/index')
        ->where('room', $room->id)
        ->has('tickets.data', 1)
        ->where('tickets.data.0.title', 'Leaking tap')
        ->where('tickets.data.0.category', 'Repair')
        ->where('tickets.data.0.priority', 'High')
        ->where('tickets.data.0.place', 'Room 101')
        ->where('tickets.data.0.assignee', null)
        ->where('tickets.data.0.reportedBy', $housekeeper->name)
        ->where('tickets.data.0.done', false));
});

function maintenanceTicket(Property $property, User $reporter, array $overrides = []): Ticket
{
    return inTenant($property, fn () => app(ReportTicket::class)->handle($reporter, ['title' => 'Broken lamp', 'description' => 'Lobby lamp flickers.', 'category' => 'repair', 'priority' => 'low', 'room_id' => null, 'location' => 'Lobby', ...$overrides]));
}

test('each role may list, report, assign and complete only as its permissions allow', function (OrganizationRole $role, bool $reads, bool $manages) {
    $t = twoTenants();
    $member = $t->a->member($role);
    $ticket = maintenanceTicket($t->a->property, $t->a->owner);
    $intent = ['_property' => $t->a->property->id];
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($member);

    $this->get(route('maintenance.index'))->assertStatus($reads ? 200 : 403);
    $this->get(route('maintenance.show', $ticket))->assertStatus($reads ? 200 : 403);
    $this->post(route('maintenance.store'), maintenanceReport($t->a->property))->assertStatus($reads ? 302 : 403);
    $this->post(route('maintenance.assign', $ticket), [...$intent, 'assignee_id' => $t->a->member(OrganizationRole::Housekeeping)->id])->assertStatus($manages ? 302 : 403);
    $this->post(route('maintenance.complete', $ticket), $intent)->assertStatus($manages ? 302 : 403);

    expect(inTenant($t->a->property, fn () => $ticket->fresh()->completed_at !== null))->toBe($manages);
})->with([
    [OrganizationRole::Owner, true, true],
    [OrganizationRole::GroupOperationsManager, true, true],
    [OrganizationRole::PropertyManager, true, true],
    [OrganizationRole::FrontDesk, true, false],
    [OrganizationRole::Housekeeping, true, false],
    [OrganizationRole::Finance, false, false],
    [OrganizationRole::Auditor, false, false],
]);

test('an assigned staff member completes their own ticket but not someone else\'s', function () {
    $t = twoTenants();
    $housekeeper = $t->a->member(OrganizationRole::Housekeeping);
    $frontDesk = $t->a->member(OrganizationRole::FrontDesk);
    $mine = maintenanceTicket($t->a->property, $t->a->owner);
    $theirs = maintenanceTicket($t->a->property, $t->a->owner);
    inTenant($t->a->property, function () use ($t, $mine, $theirs, $housekeeper, $frontDesk) {
        app(AssignTicket::class)->handle($t->a->owner, $mine, $housekeeper->id);
        app(AssignTicket::class)->handle($t->a->owner, $theirs, $frontDesk->id);
    });
    $intent = ['_property' => $t->a->property->id];
    $this->actingAs($housekeeper);

    $this->get(route('maintenance.index', ['view' => 'mine']))->assertInertia(fn (Assert $page) => $page->has('tickets.data', 1)->where('tickets.data.0.id', $mine->id));
    $this->get(route('maintenance.show', $mine))->assertInertia(fn (Assert $page) => $page->where('canComplete', true)->where('canAssign', false)->where('assignees', []));
    $this->post(route('maintenance.complete', $theirs), $intent)->assertForbidden();
    $this->post(route('maintenance.complete', $mine), $intent)->assertRedirect()->assertSessionHasNoErrors();

    $this->get(route('maintenance.index', ['view' => 'done']))->assertInertia(fn (Assert $page) => $page->has('tickets.data', 1)->where('tickets.data.0.id', $mine->id)->where('tickets.data.0.done', true));
    $this->get(route('maintenance.index'))->assertInertia(fn (Assert $page) => $page->has('tickets.data', 1)->where('tickets.data.0.id', $theirs->id));
});

test('a former assignee holding a stale ticket cannot complete it after reassignment', function () {
    $t = twoTenants();
    $housekeeper = $t->a->member(OrganizationRole::Housekeeping);
    $frontDesk = $t->a->member(OrganizationRole::FrontDesk);
    $ticket = maintenanceTicket($t->a->property, $t->a->owner);

    inTenant($t->a->property, function () use ($t, $ticket, $housekeeper, $frontDesk) {
        app(AssignTicket::class)->handle($t->a->owner, $ticket, $housekeeper->id);
        $stale = $ticket->fresh();
        app(AssignTicket::class)->handle($t->a->owner, $ticket, $frontDesk->id);

        expect(fn () => app(CompleteTicket::class)->handle($housekeeper, $stale))->toThrow(ValidationException::class);
        expect($ticket->fresh()->completed_at)->toBeNull();
    });
});

test('rooms and assignees from another property or organization are refused without a partial write', function () {
    $t = twoTenants();
    $otherPropertyRoom = maintenanceRoom($t->a->otherProperty, $t->a->owner, '201');
    $bravoRoom = maintenanceRoom($t->b->property, $t->b->owner, '301');
    $ticket = maintenanceTicket($t->a->property, $t->a->owner);
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner);

    foreach ([$otherPropertyRoom->id, $bravoRoom->id, 'missing'] as $roomId) {
        $this->postJson(route('maintenance.store'), maintenanceReport($t->a->property, ['room_id' => $roomId]))->assertUnprocessable()->assertJsonValidationErrors('room_id');
    }
    foreach ([$t->b->member(OrganizationRole::Housekeeping)->id, $t->a->member(OrganizationRole::Finance)->id, 'missing'] as $assigneeId) {
        $this->postJson(route('maintenance.assign', $ticket), ['_property' => $t->a->property->id, 'assignee_id' => $assigneeId])->assertUnprocessable()->assertJsonValidationErrors('assignee_id');
    }

    expect(inTenant($t->a->organization, fn () => Ticket::query()->count()))->toBe(1)
        ->and(inTenant($t->a->property, fn () => [$ticket->fresh()->assignee_id, TicketEvent::query()->count()]))->toBe([null, 1]);
});

test('a staff member of another property cannot be assigned and cannot reach the ticket', function () {
    $t = twoTenants();
    $ticket = maintenanceTicket($t->a->otherProperty, $t->a->owner);
    $housekeeper = $t->a->member(OrganizationRole::Housekeeping);
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->otherProperty->id])->actingAs($t->a->owner)
        ->postJson(route('maintenance.assign', $ticket), ['_property' => $t->a->otherProperty->id, 'assignee_id' => $housekeeper->id])->assertUnprocessable()->assertJsonValidationErrors('assignee_id');

    $this->actingAs($housekeeper)->post(route('maintenance.complete', $ticket), ['_property' => $t->a->otherProperty->id])->assertForbidden();
    $this->post(route('maintenance.complete', $ticket), ['_property' => $t->a->property->id])->assertNotFound();
    expect(inTenant($t->a->otherProperty, fn () => $ticket->fresh()->completed_at))->toBeNull();
});

test('reports need a title, description, known category and priority, and accept a common area instead of a room', function () {
    $t = twoTenants();
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));

    $this->postJson(route('maintenance.store'), ['_property' => $t->a->property->id, 'category' => 'cosmetic', 'priority' => 'urgent'])->assertUnprocessable()
        ->assertJsonValidationErrors(['title', 'description', 'category', 'priority']);
    $this->post(route('maintenance.store'), maintenanceReport($t->a->property, ['location' => 'Pool deck', 'category' => 'preventive']))->assertSessionHasNoErrors();

    $this->get(route('maintenance.index'))->assertInertia(fn (Assert $page) => $page->has('tickets.data', 1)->where('tickets.data.0.place', 'Pool deck')->where('tickets.data.0.category', 'Preventive'));
});

test('history keeps a removed member as actor while the member loses access on the next request', function () {
    $t = twoTenants();
    $housekeeper = $t->a->member(OrganizationRole::Housekeeping);
    $frontDesk = $t->a->member(OrganizationRole::FrontDesk);
    $ticket = maintenanceTicket($t->a->property, $housekeeper);
    inTenant($t->a->property, function () use ($t, $ticket, $housekeeper, $frontDesk) {
        app(AssignTicket::class)->handle($t->a->owner, $ticket, $housekeeper->id);
        app(AssignTicket::class)->handle($t->a->owner, $ticket, $frontDesk->id);
        app(AssignTicket::class)->handle($t->a->owner, $ticket, $housekeeper->id);
    });
    $intent = ['_property' => $t->a->property->id];
    $this->actingAs($housekeeper)->get(route('maintenance.show', $ticket))->assertOk();

    inTenant($t->a->organization, fn () => app(RemoveMember::class)->handle($t->a->owner, OrganizationMembership::query()->where('user_id', $housekeeper->id)->sole()));

    $this->actingAs($housekeeper->fresh())->get(route('maintenance.show', $ticket))->assertRedirect();
    $this->post(route('maintenance.complete', $ticket), $intent)->assertRedirect();
    expect(inTenant($t->a->property, fn () => $ticket->fresh()->completed_at))->toBeNull();

    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)
        ->get(route('maintenance.show', $ticket))->assertInertia(fn (Assert $page) => $page->where('ticket.reportedBy', $housekeeper->name)
        ->where('history.0.event', 'reported')->where('history.0.actor', $housekeeper->name)
        ->where('history.1.event', 'assigned')->where('history.1.actor', $t->a->owner->name)->where('history.1.to', $housekeeper->name)
        ->where('history.2.event', 'reassigned')->where('history.2.from', $housekeeper->name)->where('history.2.to', $frontDesk->name)
        ->where('history.3.event', 'reassigned')->where('history.3.to', $housekeeper->name)
        ->has('history', 4)->where('assignees', fn ($assignees) => ! collect($assignees)->contains('id', $housekeeper->id)));
});

test('completing a ticket leaves room readiness, availability, folios and reservations unchanged', function () {
    $t = twoTenants();
    $snapshot = fn () => inTenant($t->a->property, fn () => [
        RoomStatus::query()->orderBy('id')->get()->map->getAttributes()->all(),
        InventoryRecord::query()->orderBy('id')->get()->map->getAttributes()->all(),
        OutOfServicePeriod::query()->count(),
        Reservation::query()->orderBy('id')->get()->map->getAttributes()->all(),
        Folio::query()->orderBy('id')->get()->map->getAttributes()->all(),
    ]);
    $room = inTenant($t->a->property, function () use ($t) {
        app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));

        return Room::query()->sole();
    });
    $before = $snapshot();
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->member(OrganizationRole::Housekeeping))
        ->post(route('maintenance.store'), maintenanceReport($t->a->property, ['room_id' => $room->id]))->assertSessionHasNoErrors();
    $ticket = inTenant($t->a->property, fn () => Ticket::query()->sole());
    $this->actingAs($t->a->owner)->post(route('maintenance.complete', $ticket), ['_property' => $t->a->property->id])->assertSessionHasNoErrors();

    expect($snapshot())->toEqual($before);
});

test('maintenance pages carry no guest, identity or folio data', function () {
    $t = twoTenants();
    $room = inTenant($t->a->property, function () use ($t) {
        $guest = Guest::query()->create(['name' => 'PRIVATE-GUEST']);
        $guest->documents()->create(['type' => 'passport', 'number' => 'SECRET-PASSPORT', 'nationality' => 'TZ', 'issuing_country' => 'TZ', 'expiry' => '2030-12-31']);
        app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));

        return Room::query()->sole();
    });
    $ticket = maintenanceTicket($t->a->property, $t->a->owner, ['room_id' => $room->id, 'location' => null]);
    $this->actingAs($t->a->member(OrganizationRole::Housekeeping));

    foreach ([route('maintenance.index'), route('maintenance.show', $ticket)] as $url) {
        $this->get($url)->assertOk()->assertSee('Room 101')->assertDontSee('PRIVATE-GUEST')->assertDontSee('SECRET-PASSPORT')->assertDontSee('Travel Booker')
            ->assertInertia(fn (Assert $page) => $page->missing('guests')->missing('folios')->missing('reservations'));
    }
});
