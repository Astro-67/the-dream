<?php

use App\Domains\Billing\Actions\OpenFolio;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\ReversePosting;
use App\Domains\Billing\Actions\TransferPosting;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Support\Money\Currency;
use Illuminate\Validation\ValidationException;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;

test('an extra folio opens in any currency the jurisdiction allows, and balances on its own', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $reservation = app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
        $usd = Folio::query()->where('reservation_id', $reservation->id)->sole();

        $tzs = app(OpenFolio::class)->handle($owner, $reservation, 'TZS');
        $company = app(OpenFolio::class)->handle($owner, $reservation, 'USD');
        app(PostExtraCharge::class)->handle($owner, $tzs, ['category' => 'laundry', 'quantity' => 1, 'unit_price' => 5000]);
        app(PostExtraCharge::class)->handle($owner, $usd, ['category' => 'minibar', 'quantity' => 1, 'unit_price' => 300]);

        expect($tzs->currency)->toBe(Currency::TZS)->and($company->currency)->toBe(Currency::USD)
            ->and(Folio::query()->where('reservation_id', $reservation->id)->count())->toBe(3)
            ->and(Folio::query()->findOrFail($tzs->id)->balance()->amount)->toBe(5000)
            ->and(Folio::query()->findOrFail($usd->id)->balance()->amount)->toBe(300)
            ->and(fn () => app(OpenFolio::class)->handle($owner, $reservation, 'GBP'))->toThrow(ValidationException::class);
    });
});

test('a transfer moves a charge between folios through linked postings', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $reservation = app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
        $guest = Folio::query()->where('reservation_id', $reservation->id)->sole();
        $company = app(OpenFolio::class)->handle($owner, $reservation, 'USD');
        $charge = app(PostExtraCharge::class)->handle($owner, $guest, ['category' => 'minibar', 'quantity' => 2, 'unit_price' => 300]);

        $in = app(TransferPosting::class)->handle($owner, $charge, $company, 'Company pays extras');

        $out = Posting::query()->where('related_posting_id', $charge->id)->sole();
        expect($out->only(['type', 'folio_id', 'amount']))->toBe(['type' => 'transfer_out', 'folio_id' => $guest->id, 'amount' => -600])
            ->and($in->only(['type', 'folio_id', 'related_posting_id', 'amount', 'category', 'reason']))->toBe(['type' => 'transfer_in', 'folio_id' => $company->id, 'related_posting_id' => $out->id, 'amount' => 600, 'category' => 'minibar', 'reason' => 'Company pays extras'])
            ->and(Folio::query()->findOrFail($guest->id)->balance()->amount)->toBe(0)
            ->and(Folio::query()->findOrFail($company->id)->balance()->amount)->toBe(600)
            ->and(fn () => app(TransferPosting::class)->handle($owner, $charge, $company, 'Again'))->toThrow(ValidationException::class)
            ->and(fn () => app(ReversePosting::class)->handle($owner, $charge, 'After moving'))->toThrow(ValidationException::class);

        app(ReversePosting::class)->handle($owner, $in, 'Company declined');
        expect(Folio::query()->findOrFail($company->id)->balance()->amount)->toBe(0);
    });
});

test('a charge cannot move into a folio in another currency, on another reservation, or onto its own folio', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $booking = BookingForm::details($owner);
        $reservation = app(CreateReservation::class)->handle($owner, $booking);
        $guest = Folio::query()->where('reservation_id', $reservation->id)->sole();
        $tzs = app(OpenFolio::class)->handle($owner, $reservation, 'TZS');
        app(CreateRoom::class)->handle($owner, InventoryForm::room($booking['room_type_id'], ['number' => '102']));
        $other = Folio::query()->where('reservation_id', app(CreateReservation::class)->handle($owner, $booking)->id)->sole();
        $charge = app(PostExtraCharge::class)->handle($owner, $guest, ['category' => 'minibar', 'quantity' => 1, 'unit_price' => 300]);

        foreach ([$tzs, $other, $guest] as $target) {
            expect(fn () => app(TransferPosting::class)->handle($owner, $charge, $target, 'Move'))->toThrow(ValidationException::class);
        }
        expect(Posting::query()->count())->toBe(1);
    });
});
