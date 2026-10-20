<?php

use App\Domains\Guests\Actions\AttachReservationGuest;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;

test('calendar and reservation lookup find each supported field', function (string $field) {
    $t = twoTenants();
    [$reservation, $term] = inTenant($t->a->property, function () use ($t, $field) {
        $details = BookingForm::details($t->a->owner);
        $room = Room::query()->sole();
        $room->update(['number' => 'SUITE-729']);
        $reservation = app(CreateReservation::class)->handle($t->a->owner, [...$details, 'room_id' => $room->id]);
        $reservation->update(['channel_reference' => 'CHANNEL-829']);
        app(AttachReservationGuest::class)->handle($t->a->owner, $reservation, ['name' => 'Calendar Finder', 'phone' => '+255777222999']);

        return [$reservation, match ($field) {
            'room' => 'SUITE-729', 'guest' => 'Calendar Finder', 'phone' => '777222999',
            'number' => $reservation->reservation_number, 'channel' => 'CHANNEL-829',
        }];
    });
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner);
    foreach ([$term => 1, 'Not a matching stay' => 0] as $search => $count) {
        $this->get(route('rate-calendar', ['from' => '2026-12-10', 'to' => '2026-12-12', 'search' => $search]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('roomTypes.0.rooms.0.reservations', $count));
        $this->get(route('reservations.index', ['list' => 'all', 'search' => $search]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('reservations.data', $count)
            ->when($count === 1, fn (Assert $page) => $page->where('reservations.data.0.id', $reservation->id)));
    }
})->with(['room', 'guest', 'phone', 'number', 'channel']);

test('calendar views cover the requested number of days', function (int $days) {
    $t = twoTenants();
    $this->actingAs($t->a->owner)->get(route('rate-calendar', ['from' => '2026-12-10', 'days' => $days]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('dates', $days)->where('dates.0', '2026-12-10')
        ->where('to', CarbonImmutable::parse('2026-12-10')->addDays($days - 1)->toDateString()));
})->with([7, 15, 30, 60]);

test('calendar validates search filter and view inputs', function () {
    $t = twoTenants();
    $this->actingAs($t->a->owner)->getJson(route('rate-calendar', ['search' => ['bad'], 'list' => 'unknown', 'days' => 90]))
        ->assertUnprocessable()->assertJsonValidationErrors(['search', 'list', 'days']);
});
