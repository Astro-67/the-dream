<?php

use App\Domains\Guests\Actions\AttachReservationGuest;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\Guest;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;

test('guest profile lists only their stays with dates and status alongside preferences and notes', function () {
    $t = twoTenants();
    [$guest, $reservation] = inTenant($t->a->property, function () use ($t) {
        $guest = app(CreateGuest::class)->handle($t->a->owner, ['name' => 'Returning Guest', 'preferences' => 'Quiet room', 'notes' => 'Late arrival']);
        $details = BookingForm::details($t->a->owner);
        $reservation = app(CreateReservation::class)->handle($t->a->owner, $details);
        app(AttachReservationGuest::class)->handle($t->a->owner, $reservation, ['guest_id' => $guest->id]);

        return [$guest, $reservation];
    });
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get(route('guests.show', $guest))
        ->assertInertia(fn (Assert $page) => $page->component('guests/show')
            ->where('record.preferences', 'Quiet room')->where('record.notes', 'Late arrival')
            ->where('stays.total', 1)->where('stays.data.0.reservation_number', $reservation->reservation_number)
            ->where('stays.data.0.arrival', $reservation->arrival->toDateString())
            ->where('stays.data.0.departure', $reservation->departure->toDateString())
            ->where('stays.data.0.status', 'confirmed'));
    $empty = inTenant($t->a->property, fn () => Guest::query()->create(['name' => 'No stays']));
    $this->get(route('guests.show', $empty))->assertInertia(fn (Assert $page) => $page->where('stays.total', 0));
});
