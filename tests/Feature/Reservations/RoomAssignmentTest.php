<?php

use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Models\Reservation;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;

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
