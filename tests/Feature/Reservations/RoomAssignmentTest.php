<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\RoomAssignmentNight;
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
