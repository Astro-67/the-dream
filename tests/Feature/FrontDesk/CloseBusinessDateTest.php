<?php

use App\Domains\Billing\Actions\PostRoomCharge;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\FrontDesk\Actions\CloseBusinessDate;
use App\Domains\FrontDesk\BusinessDate;
use App\Domains\FrontDesk\Models\BusinessDateClose;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Rates\Actions\CreateTaxRule;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Support\Money\Currency;
use Illuminate\Validation\ValidationException;
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

test('repeating a close keeps the original actor and charges and does not advance again', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['business_date' => '2026-12-10']);
    inTenant($property, function () use ($owner, $property) {
        $reservation = app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
        $reservation->update(['status' => ReservationStatus::CheckedIn]);
        $first = app(CloseBusinessDate::class)->handle(null, $property, '2026-12-10');
        $posting = Posting::query()->sole();
        $reservation->priceLines()->whereDate('date', '2026-12-10')->update(['amount' => 99999]);

        $again = app(CloseBusinessDate::class)->handle($owner, $property, '2026-12-10');

        expect($again->id)->toBe($first->id)->and($again->actor_id)->toBeNull()
            ->and(Posting::query()->sole()->id)->toBe($posting->id)
            ->and(Posting::query()->sole()->amount)->toBe(11000)
            ->and(BusinessDateClose::query()->count())->toBe(1)
            ->and(BusinessDate::of($property->fresh()))->toBe('2026-12-11');
    });
});

test('a failed close rolls back all new charges and can resume beside a charge already posted', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['business_date' => '2026-12-10']);
    inTenant($property, function () use ($owner, $property) {
        $details = BookingForm::details($owner);
        foreach (range(2, 3) as $number) {
            app(CreateRoom::class)->handle($owner, [...InventoryForm::room($details['room_type_id']), 'number' => (string) $number]);
        }
        $reservations = [];
        foreach (range(1, 3) as $number) {
            $reservation = app(CreateReservation::class)->handle($owner, $details);
            $reservation->update(['status' => ReservationStatus::CheckedIn]);
            $reservations[] = $reservation;
        }
        $already = app(PostRoomCharge::class)->handle(null, $reservations[0], '2026-12-10');
        $line = $reservations[2]->priceLines()->whereDate('date', '2026-12-10')->sole();
        $attributes = $line->only(['rate_plan_id', 'date', 'amount', 'currency']);
        $line->delete();

        expect(fn () => app(CloseBusinessDate::class)->handle(null, $property, '2026-12-10'))->toThrow(ValidationException::class)
            ->and(Posting::query()->sole()->id)->toBe($already->id)
            ->and(BusinessDateClose::query()->count())->toBe(0)
            ->and(BusinessDate::of($property->fresh()))->toBe('2026-12-10');

        $reservations[2]->priceLines()->create($attributes);
        app(CloseBusinessDate::class)->handle(null, $property, '2026-12-10');
        app(CloseBusinessDate::class)->handle(null, $property, '2026-12-10');

        expect(Posting::query()->count())->toBe(3)
            ->and(Folio::query()->where('reservation_id', $reservations[0]->id)->sole()->balance()->amount)->toBe(11000)
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

test('closing requires the named property context even within the same organization', function () {
    $tenants = twoTenants();
    $tenants->a->otherProperty->update(['business_date' => '2026-12-10']);
    inTenant($tenants->a->property, function () use ($tenants) {
        expect(fn () => app(CloseBusinessDate::class)->handle(null, $tenants->a->otherProperty, '2026-12-10'))
            ->toThrow(ValidationException::class);
        expect(BusinessDateClose::query()->count())->toBe(0)
            ->and(BusinessDate::of($tenants->a->otherProperty->fresh()))->toBe('2026-12-10');
    });
});

test('room charging refuses a reservation that is not in house', function (ReservationStatus $status) {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['business_date' => '2026-12-10']);
    inTenant($property, function () use ($owner, $status) {
        $reservation = app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
        $reservation->update(['status' => $status]);
        expect(fn () => app(PostRoomCharge::class)->handle($owner, $reservation, '2026-12-10'))
            ->toThrow(ValidationException::class);
        expect(Posting::query()->count())->toBe(0);
    });
})->with([ReservationStatus::Confirmed, ReservationStatus::CheckedOut, ReservationStatus::Cancelled]);
