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


test('inspection pass and fail require supervision and preserve history on refusal', function (OrganizationRole $role, string $to) {
    $t = twoTenants();
    $room = inTenant($t->a->property, function () use ($t) {
        $t->a->property->update(['housekeeping_inspection_required' => true]);
        $type = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());
        $room = app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($type->id));
        app(MarkRoomCleaned::class)->handle($t->a->owner, $room);

        return $room;
    });
    $this->actingAs($t->a->member($role));
    $allowed = in_array($role, [OrganizationRole::Owner, OrganizationRole::GroupOperationsManager, OrganizationRole::PropertyManager], true);
    $response = $this->patchJson(route('housekeeping.status', $room), ['_property' => $t->a->property->id, 'status' => $to, 'reason' => 'Inspection result']);
    if ($allowed) {
        $response->assertRedirect();
    } else {
        $response->assertForbidden();
    }
    inTenant($t->a->property, function () use ($allowed, $to) {
        expect(RoomStatus::query()->sole()->status->value)->toBe($allowed ? $to : 'inspection_required')
            ->and(StatusChange::query()->count())->toBe($allowed ? 2 : 1);
        if ($allowed) {
            expect(StatusChange::query()->orderByDesc('id')->first()->reason)->toBe('Inspection result');
        }
    });
})->with(OrganizationRole::cases())->with(['clean', 'dirty']);
