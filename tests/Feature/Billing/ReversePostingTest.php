<?php

use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\ReversePosting;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Rates\Actions\CreateTaxRule;
use App\Domains\Reservations\Actions\CreateReservation;
use Illuminate\Validation\ValidationException;
use Tests\Support\BookingForm;

test('a reversal links to the original, keeps the reason and cancels its amount and tax lines', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        app(CreateTaxRule::class)->handle($owner, ['name' => 'VAT', 'kind' => 'percentage', 'rate' => 1800, 'amount' => null, 'currency' => null, 'inclusive' => false, 'applies_to' => 'extras', 'rounding' => 'half_up', 'effective_from' => '2020-01-01', 'effective_to' => null]);
        $folio = Folio::query()->where('reservation_id', app(CreateReservation::class)->handle($owner, BookingForm::details($owner))->id)->sole();
        $charge = app(PostExtraCharge::class)->handle($owner, $folio, ['category' => 'minibar', 'quantity' => 2, 'unit_price' => 250]);

        $reversal = app(ReversePosting::class)->handle($owner, $charge, 'Guest did not use the minibar');

        expect($reversal->only(['type', 'category', 'related_posting_id', 'reason', 'net', 'amount']))
            ->toBe(['type' => 'reversal', 'category' => 'minibar', 'related_posting_id' => $charge->id, 'reason' => 'Guest did not use the minibar', 'net' => -500, 'amount' => -590])
            ->and($reversal->tax_lines[0]['amount'])->toBe(-90)
            ->and($reversal->folio_id)->toBe($folio->id)
            ->and(Folio::query()->findOrFail($folio->id)->balance()->amount)->toBe(0)
            ->and(Posting::query()->findOrFail($charge->id)->amount)->toBe(590);
    });
});

test('a posting can be reversed only once, and a reversal cannot itself be reversed', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $folio = Folio::query()->where('reservation_id', app(CreateReservation::class)->handle($owner, BookingForm::details($owner))->id)->sole();
        $charge = app(PostExtraCharge::class)->handle($owner, $folio, ['category' => 'laundry', 'quantity' => 1, 'unit_price' => 1000]);
        $reversal = app(ReversePosting::class)->handle($owner, $charge, 'Wrong room');

        expect(fn () => app(ReversePosting::class)->handle($owner, $charge, 'Again'))->toThrow(ValidationException::class)
            ->and(fn () => app(ReversePosting::class)->handle($owner, $reversal, 'Undo'))->toThrow(ValidationException::class)
            ->and(Posting::query()->count())->toBe(2);
    });
});

test('a reversal requires a reason', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $folio = Folio::query()->where('reservation_id', app(CreateReservation::class)->handle($owner, BookingForm::details($owner))->id)->sole();
        $charge = app(PostExtraCharge::class)->handle($owner, $folio, ['category' => 'other', 'quantity' => 1, 'unit_price' => 100]);

        expect(fn () => app(ReversePosting::class)->handle($owner, $charge, '  '))->toThrow(ValidationException::class)
            ->and(Posting::query()->count())->toBe(1);
    });
});
