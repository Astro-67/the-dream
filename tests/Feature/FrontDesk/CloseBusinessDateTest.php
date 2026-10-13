<?php

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\FrontDesk\Actions\CloseBusinessDate;
use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Rates\Actions\CreateTaxRule;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Support\Money\Currency;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;

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

test('a fixture close charges only in-house reservations including departures and overdue departures', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['business_date' => '2026-12-12']);
    inTenant($property, function () use ($owner, $property) {
        $details = BookingForm::details($owner);
        foreach (range(2, 5) as $number) {
            app(CreateRoom::class)->handle($owner, [...InventoryForm::room($details['room_type_id']), 'number' => (string) $number]);
        }
        $reservations = [];
        foreach ([ReservationStatus::CheckedIn, ReservationStatus::CheckedIn, ReservationStatus::Confirmed, ReservationStatus::Confirmed, ReservationStatus::CheckedOut] as $status) {
            $reservation = app(CreateReservation::class)->handle($owner, $details);
            $reservation->update(['status' => $status]);
            $reservations[] = $reservation;
        }
        $reservations[0]->update(['departure' => '2026-12-11']);
        $reservations[0]->priceLines()->whereDate('date', '2026-12-11')->delete();
        $reservations[2]->update(['arrival' => '2026-12-12']);

        app(CloseBusinessDate::class)->handle(null, $property, '2026-12-12');

        expect(Posting::query()->orderBy('reservation_id')->pluck('reservation_id')->all())
            ->toEqualCanonicalizing([$reservations[0]->id, $reservations[1]->id]);
        foreach ([$reservations[0], $reservations[1]] as $reservation) {
            $posting = Posting::query()->where('reservation_id', $reservation->id)->sole();
            expect($posting->amount)->toBe(11000)
                ->and($posting->price_line_id)->toBe($reservation->priceLines()->reorder()->latest('date')->first()->getKey())
                ->and($reservation->fresh()->status)->toBe(ReservationStatus::CheckedIn);
        }
    });
});
