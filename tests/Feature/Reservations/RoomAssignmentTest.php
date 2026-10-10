<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\AssignRoom;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\RoomAssignmentNight;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;

test('staff can assign at booking then unassign and assign from the detail page', function () {
    $t = twoTenants();
    [$details, $room] = inTenant($t->a->property, function () use ($t) {
        $details = BookingForm::details($t->a->owner);

        return [$details, Room::query()->where('room_type_id', $details['room_type_id'])->sole()];
    });
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner);
    $response = $this->post(route('reservations.store'), [...$details, 'room_id' => $room->id, '_property' => $t->a->property->id])->assertRedirect();
    $reservation = inTenant($t->a->property, fn () => Reservation::query()->sole());
    $this->get($response->headers->get('Location'))->assertInertia(fn (Assert $page) => $page->where('reservation.room_id', $room->id));
    $this->patch(route('reservations.room-assignment', $reservation), ['room_id' => null, '_property' => $t->a->property->id])->assertRedirect();
    $this->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page->where('reservation.room_id', null));
    $this->patch(route('reservations.room-assignment', $reservation), ['room_id' => $room->id, '_property' => $t->a->property->id])->assertRedirect();
    inTenant($t->a->property, function () use ($reservation, $room) {
        expect($reservation->fresh()->room_id)->toBe($room->id);
        expect($reservation->fresh()->lifecycleEvents->pluck('type')->all())->toBe(['created', 'modified', 'modified']);
        expect(Activity::query()->where('subject_id', $reservation->id)->where('description', 'Reservation room assignment changed')->count())->toBe(3);
    });
});

test('a conflicting room night refuses reassignment without losing the original assignment', function () {
    [$owner, , $property] = organizationWithOwner();
    [$second, $rooms] = inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        $room = Room::query()->sole();
        $other = app(CreateRoom::class)->handle($owner, InventoryForm::room($details['room_type_id'], ['number' => '102']));
        app(CreateReservation::class)->handle($owner, [...$details, 'room_id' => $room->id]);

        return [app(CreateReservation::class)->handle($owner, [...$details, 'room_id' => $other->id]), [$room, $other]];
    });
    $this->actingAs($owner)->patchJson(route('reservations.room-assignment', $second), ['room_id' => $rooms[0]->id, '_property' => $property->id])->assertUnprocessable()->assertJsonValidationErrors('room_id');
    inTenant($property, function () use ($second, $rooms) {
        expect($second->fresh()->room_id)->toBe($rooms[1]->id);
        expect($second->fresh()->lifecycleEvents)->toHaveCount(1);
        expect(RoomAssignmentNight::query()->where('reservation_id', $second->id)->pluck('room_id')->all())->toBe([$rooms[1]->id, $rooms[1]->id]);
        expect(Activity::query()->where('subject_id', $second->id)->where('event', 'refused')->count())->toBe(1);
    });
});

test('room choices exclude inactive wrong type out of service and occupied rooms', function () {
    [$owner, , $property] = organizationWithOwner();
    [$reservation, $room] = inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        $room = Room::query()->sole();
        $inactive = app(CreateRoom::class)->handle($owner, InventoryForm::room($details['room_type_id'], ['number' => '102']));
        $inactive->forceFill(['deactivated_at' => now()])->save();

        return [app(CreateReservation::class)->handle($owner, $details), $room];
    });
    $this->actingAs($owner)->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page->has('rooms', 1)->where('rooms.0.id', $room->id));
});

test('assignment refuses inactive wrong type and out of service rooms', function (string $invalid) {
    [$owner, , $property] = organizationWithOwner();
    [$reservation, $room] = inTenant($property, function () use ($owner, $invalid) {
        $details = BookingForm::details($owner);
        $reservation = app(CreateReservation::class)->handle($owner, $details);
        $room = Room::query()->sole();
        if ($invalid === 'inactive') {
            $room->forceFill(['deactivated_at' => now()])->save();
        } elseif ($invalid === 'wrong type') {
            $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType(['code' => 'OTHER', 'name' => 'Other']));
            $room = app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id, ['number' => '201']));
        } else {
            $room->outOfServicePeriods()->create(['starts_on' => '2026-12-11', 'returns_on' => '2026-12-13', 'reason' => 'Repairs']);
        }

        return [$reservation, $room];
    });
    $this->actingAs($owner)->patchJson(route('reservations.room-assignment', $reservation), ['room_id' => $room->id, '_property' => $property->id])->assertUnprocessable()->assertJsonValidationErrors('room_id');
    inTenant($property, function () use ($reservation) {
        expect($reservation->fresh()->room_id)->toBeNull();
        expect(RoomAssignmentNight::query()->count())->toBe(0);
        expect($reservation->fresh()->lifecycleEvents)->toHaveCount(1);
    });
})->with(['inactive', 'wrong type', 'out of service']);

test('room assignment follows role permission', function (OrganizationRole $role, bool $allowed) {
    $t = twoTenants();
    [$reservation, $room] = inTenant($t->a->property, function () use ($t) {
        $details = BookingForm::details($t->a->owner);

        return [app(CreateReservation::class)->handle($t->a->owner, $details), Room::query()->sole()];
    });
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->member($role))->patchJson(route('reservations.room-assignment', $reservation), ['room_id' => $room->id, '_property' => $t->a->property->id])->assertStatus($allowed ? 302 : 403);
})->with([
    [OrganizationRole::Owner, true],
    [OrganizationRole::GroupOperationsManager, true],
    [OrganizationRole::PropertyManager, true],
    [OrganizationRole::FrontDesk, true],
    [OrganizationRole::Housekeeping, false],
]);

test('checkout day is free and unassigning releases all room nights', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        $room = Room::query()->sole();
        $first = app(CreateReservation::class)->handle($owner, [...$details, 'departure' => '2026-12-11', 'room_id' => $room->id]);
        $second = app(CreateReservation::class)->handle($owner, [...$details, 'arrival' => '2026-12-11', 'room_id' => $room->id]);
        expect(RoomAssignmentNight::query()->count())->toBe(2);
        app(AssignRoom::class)->handle($owner, $first, null);
        expect(RoomAssignmentNight::query()->pluck('reservation_id')->all())->toBe([$second->id]);
    });
});
