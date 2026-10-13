<?php

use App\Domains\Billing\Actions\OpenCashierShift;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Tests\Support\BookingForm;

/** A folio on a new reservation in the property in context. */
function cashierShiftFolio(User $owner): Folio
{
    $id = app(CreateReservation::class)->handle($owner, BookingForm::details($owner))->id;

    return Folio::query()->where('reservation_id', $id)->sole();
}

test('opening a shift records a float in each currency, one open shift per user and property', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $shift = app(OpenCashierShift::class)->handle($owner, ['TZS' => 500000, 'USD' => 10000]);

        expect($shift->status)->toBe('open')->and($shift->user_id)->toBe($owner->id)
            ->and($shift->floats)->toEqual(['TZS' => 500000, 'USD' => 10000]);
        expect(fn () => app(OpenCashierShift::class)->handle($owner, ['USD' => 100]))->toThrow(ValidationException::class);
    });
});

test('a cash payment needs an open shift of the cashier recording it, and counts towards it', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $folio = cashierShiftFolio($owner);
        $cash = ['method' => 'cash', 'amount' => 3000, 'currency' => 'USD'];

        expect(fn () => app(RecordPayment::class)->handle($owner, $folio, $cash))->toThrow(ValidationException::class);
        expect(Posting::query()->count())->toBe(0);

        $shift = app(OpenCashierShift::class)->handle($owner, ['USD' => 10000]);
        $payment = app(RecordPayment::class)->handle($owner, $folio, $cash);

        expect($payment->method)->toBe('cash')->and($payment->cashier_shift_id)->toBe($shift->id)
            ->and($payment->receipt?->number)->toBe('RCT-000001')
            ->and($shift->expectedCash())->toBe(['USD' => 13000]);
    });
});
