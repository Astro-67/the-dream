<?php

use App\Domains\Housekeeping\Actions\ChangeRoomStatus;
use App\Domains\Housekeeping\Actions\MarkRoomCleaned;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Housekeeping\Models\StatusChange;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Illuminate\Validation\ValidationException;
use Tests\Support\InventoryForm;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PropertyForm;

test('property managers can enable inspection and it defaults off', function () {
    $t = twoTenants();
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager));
    $this->get(route('properties.edit', $t->a->property))->assertInertia(fn (Assert $page) => $page->where('property.housekeeping_inspection_required', false));
    $this->put(route('properties.update', $t->a->property), PropertyForm::payload([
        '_property' => $t->a->property->id, 'housekeeping_inspection_required' => true,
    ]))->assertRedirect()->assertSessionHasNoErrors();
    $this->get(route('properties.edit', $t->a->property))->assertInertia(fn (Assert $page) => $page->where('property.housekeeping_inspection_required', true));
});

test('marking a room cleaned follows its property inspection setting', function (bool $required, string $expected) {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner, $property, $required, $expected) {
        $property->update(['housekeeping_inspection_required' => $required]);
        $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType());
        $room = app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id));
        app(MarkRoomCleaned::class)->handle($owner, $room);
        expect(RoomStatus::query()->sole()->status->value)->toBe($expected);
        $change = StatusChange::query()->sole();
        expect($change->from_status->value)->toBe('dirty')->and($change->to_status->value)->toBe($expected)->and($change->actor_id)->toBe($owner->id);
    });
})->with([[false, 'clean'], [true, 'inspection_required']]);
