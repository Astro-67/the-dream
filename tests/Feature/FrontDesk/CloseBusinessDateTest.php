<?php

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\FrontDesk\Actions\CloseBusinessDate;
use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Rates\Actions\CreateTaxRule;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Support\Money\Currency;
use Tests\Support\BookingForm;

test('closing the business date posts the in-house night with room taxes and opens the next date', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['business_date' => '2026-12-10']);

    inTenant($property, function () use ($owner, $property) {
        $tax = app(CreateTaxRule::class)->handle($owner, [
            'name' => 'VAT', 'kind' => 'percentage', 'rate' => 1800, 'amount' => null,
            'currency' => null, 'inclusive' => false, 'applies_to' => 'room',
            'rounding' => 'half_up', 'effective_from' => '2026-01-01', 'effective_to' => null,
        ]);
        $reservation = app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
        $reservation->update(['status' => ReservationStatus::CheckedIn]);

        $close = app(CloseBusinessDate::class)->handle($owner, $property, '2026-12-10');

        $posting = Posting::query()->sole();
        expect($posting->category)->toBe('room')
            ->and($posting->unit_price)->toBe(11000)
            ->and($posting->amount)->toBe(12980)
            ->and($posting->currency)->toBe(Currency::USD)
            ->and($posting->tax_lines[0]['amount'])->toBe(1980)
            ->and($posting->tax_rule_ids)->toEqual([$tax->id])
            ->and($posting->business_date->toDateString())->toBe('2026-12-10')
            ->and(Folio::query()->sole()->balance()->amount)->toBe(12980)
            ->and($close->actor_id)->toBe($owner->id)
            ->and($close->business_date->toDateString())->toBe('2026-12-10')
            ->and($close->completed_at)->not->toBeNull()
            ->and(BusinessDate::of($property->fresh()))->toBe('2026-12-11');
    });
});
