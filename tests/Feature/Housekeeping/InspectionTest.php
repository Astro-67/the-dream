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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InventoryForm;
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

test('board cleaning uses the inspection-aware action and exposes the setting', function () {
    $t = twoTenants();
    $room = inTenant($t->a->property, function () use ($t) {
        $t->a->property->update(['housekeeping_inspection_required' => true]);
        $type = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());

        return app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($type->id));
    });
    $this->actingAs($t->a->member(OrganizationRole::Housekeeping));
    $this->get(route('housekeeping.index'))->assertInertia(fn (Assert $page) => $page->where('inspectionRequired', true)->where('canSupervise', false));
    $this->patchJson(route('housekeeping.status', $room), ['_property' => $t->a->property->id, 'status' => 'cleaned'])->assertRedirect();
    $this->get(route('housekeeping.index'))->assertInertia(fn (Assert $page) => $page->where('rooms.0.status', 'inspection_required'));
});

test('all status transitions honor both inspection settings', function (bool $required, string $from, string $to, bool $allowed) {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner, $property, $required, $from, $to, $allowed) {
        $property->update(['housekeeping_inspection_required' => $required]);
        $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType());
        $room = app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id));
        RoomStatus::query()->sole()->update(['status' => $from]);
        if ($allowed) {
            app(ChangeRoomStatus::class)->handle($owner, $room, HousekeepingStatus::from($to), 'Status changed');
        } else {
            expect(fn () => app(ChangeRoomStatus::class)->handle($owner, $room, HousekeepingStatus::from($to), 'Status changed'))->toThrow(ValidationException::class);
        }
        expect(RoomStatus::query()->sole()->status->value)->toBe($allowed ? $to : $from)
            ->and(StatusChange::query()->count())->toBe($allowed ? 1 : 0);
    });
})->with(function () {
    foreach ([false, true] as $required) {
        $permitted = $required
            ? ['dirty:inspection_required', 'clean:dirty', 'inspection_required:clean', 'inspection_required:dirty']
            : ['dirty:clean', 'clean:dirty', 'inspection_required:clean', 'inspection_required:dirty'];
        foreach (HousekeepingStatus::cases() as $from) {
            foreach (HousekeepingStatus::cases() as $to) {
                yield ($required ? 'inspection on ' : 'inspection off ').$from->value.':'.$to->value => [$required, $from->value, $to->value, in_array($from->value.':'.$to->value, $permitted, true)];
            }
        }
    }
});

test('failed inspection requires a nonblank reason at the action boundary', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner, $property) {
        $property->update(['housekeeping_inspection_required' => true]);
        $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType());
        $room = app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id));
        app(MarkRoomCleaned::class)->handle($owner, $room);
        expect(fn () => app(ChangeRoomStatus::class)->handle($owner, $room, HousekeepingStatus::Dirty, '   '))->toThrow(ValidationException::class);
        expect(RoomStatus::query()->sole()->status)->toBe(HousekeepingStatus::InspectionRequired)->and(StatusChange::query()->count())->toBe(1);
    });
});
