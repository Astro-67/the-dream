<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Models\Room;
use App\Domains\Maintenance\Actions\AssignTicket;
use App\Domains\Maintenance\Actions\ReportTicket;
use App\Domains\Maintenance\Models\Ticket;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
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

    $this->get(route('maintenance.index'))->assertInertia(fn (Assert $page) => $page->component('maintenance/index')
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
