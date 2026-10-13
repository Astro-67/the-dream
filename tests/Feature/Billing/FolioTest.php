<?php

use App\Domains\Billing\Models\Folio;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Support\Money\Currency;
use Tests\Support\BookingForm;

test('a new reservation has one folio in its rate plan currency', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $reservation = app(CreateReservation::class)->handle($owner, BookingForm::details($owner));

        $folio = Folio::query()->where('reservation_id', $reservation->id)->sole();
        expect($folio->currency)->toBe(Currency::USD);
    });
});
