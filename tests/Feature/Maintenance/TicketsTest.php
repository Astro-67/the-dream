<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Models\Room;
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
