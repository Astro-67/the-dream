<?php

use App\Domains\Reservations\Actions\CancelReservation;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia;
use Tests\Support\BookingForm;

test('cancelling releases every night for resale without reusing the reservation number', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        $reservation = app(CreateReservation::class)->handle($owner, $details);
        $cancelled = app(CancelReservation::class)->handle($owner, $reservation, 'Guest changed plans');
        expect($cancelled->status)->toBe(ReservationStatus::Cancelled);
        $event = $cancelled->lifecycleEvents()->where('type', 'cancelled')->sole();
        expect($event->sequence)->toBe(2)->and($event->actor_id)->toBe($owner->id)
            ->and($event->snapshot['reason'])->toBe('Guest changed plans')
            ->and($event->snapshot['status'])->toBe('cancelled');
        $replacement = app(CreateReservation::class)->handle($owner, $details);
        expect($replacement->reservation_number)->not->toBe($cancelled->reservation_number);
    });
});

test('front desk cancels through the property intent route with a required reason', function () {
    $t = twoTenants();
    $reservation = inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));
    $this->postJson(route('reservations.cancel', $reservation), ['_property' => $t->a->property->id, 'reason' => '   '])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->post(route('reservations.cancel', $reservation), ['_property' => $t->a->property->id, 'reason' => 'Guest changed plans'])->assertRedirect(route('reservations.show', $reservation));
    $this->get(route('reservations.show', $reservation))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('reservation.status', 'cancelled'));
});
