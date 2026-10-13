<?php

use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\Folio;
use App\Domains\Reservations\Actions\CreateReservation;
use Tests\Support\BookingForm;

test('a bank transfer posts to the folio, reduces its balance and gets a receipt', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $folio = Folio::query()->where('reservation_id', app(CreateReservation::class)->handle($owner, BookingForm::details($owner))->id)->sole();

        $payment = app(RecordPayment::class)->handle($owner, $folio, ['method' => 'bank_transfer', 'amount' => 5000, 'currency' => 'USD', 'reference' => 'TT-889']);

        expect($payment->only(['type', 'method', 'reference', 'amount']))->toBe(['type' => 'payment', 'method' => 'bank_transfer', 'reference' => 'TT-889', 'amount' => -5000])
            ->and($folio->fresh()->balance()->amount)->toBe(-5000)
            ->and($payment->receipt?->number)->toBe('RCT-000001');
    });
});
