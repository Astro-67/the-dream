<?php

use App\Domains\Reservations\Actions\CancelReservation;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
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
