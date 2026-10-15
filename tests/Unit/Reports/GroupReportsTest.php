<?php

use App\Domains\Billing\Actions\OpenFolio;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\RecordAdjustment;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Actions\ReversePosting;
use App\Domains\Billing\Models\Folio;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Reports\GroupReports;
use App\Domains\Reports\PropertyFinance;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\ReservationPriceLine;
use App\Domains\Tenancy\Actions\CreateDemoProperty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('group KPIs add numerators and denominators and keep currencies apart', function () {
    $t = twoTenants();
    $t->a->otherProperty->update(['currency' => 'TZS']);
    inTenant($t->a->property, function () use ($t) {
        app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
        InventoryRecord::query()->whereDate('date', '2026-12-10')->update(['sellable_count' => 4]);
        InventoryRecord::query()->whereDate('date', '2026-12-11')->update(['sellable_count' => 6]);
    });
    inTenant($t->a->otherProperty, function () use ($t) {
        app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
        ReservationPriceLine::query()->update(['currency' => 'TZS', 'amount' => 50000]);
    });

    $kpis = inTenant($t->a->organization, fn () => app(GroupReports::class)
        ->forProperties(collect([$t->a->property, $t->a->otherProperty]), '2026-12-10', '2026-12-11'))['kpis'];

    // Averaging the property occupancies (20% and 100%) would give 60%.
    expect($kpis)->toEqual([
        'soldRoomNights' => 4,
        'sellableRoomNights' => 12,
        'occupancy' => 4 / 12,
        'currencies' => [
            ['currency' => 'TZS', 'bookedRoomValue' => 100000, 'soldRoomNights' => 2, 'sellableRoomNights' => 2, 'bookedAdr' => 50000, 'bookedRevpar' => 50000],
            ['currency' => 'USD', 'bookedRoomValue' => 22000, 'soldRoomNights' => 2, 'sellableRoomNights' => 10, 'bookedAdr' => 11000, 'bookedRevpar' => 2200],
        ],
    ]);
});

test('demo properties never contribute and empty groups mark zero denominators not applicable', function () {
    $t = twoTenants();
    $demo = inTenant($t->a->property, fn () => app(CreateDemoProperty::class)->handle($t->a->owner));
    $t->a->otherProperty->update(['currency' => 'TZS']);

    $kpis = inTenant($t->a->property, fn () => app(GroupReports::class)
        ->forProperties(collect([$demo, $t->a->property, $t->a->otherProperty]), '2026-12-10', '2026-12-11'))['kpis'];

    expect($kpis)->toEqual([
        'soldRoomNights' => 0,
        'sellableRoomNights' => 0,
        'occupancy' => null,
        'currencies' => [
            ['currency' => 'TZS', 'bookedRoomValue' => 0, 'soldRoomNights' => 0, 'sellableRoomNights' => 0, 'bookedAdr' => null, 'bookedRevpar' => null],
            ['currency' => 'USD', 'bookedRoomValue' => 0, 'soldRoomNights' => 0, 'sellableRoomNights' => 0, 'bookedAdr' => null, 'bookedRevpar' => null],
        ],
    ]);
});

test('group operations add each business date and current housekeeping status across properties', function () {
    $t = twoTenants();
    foreach ([$t->a->property, $t->a->otherProperty] as $property) {
        inTenant($property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    }
    inTenant($t->a->otherProperty, fn () => RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]));

    $operations = inTenant($t->a->organization, fn () => app(GroupReports::class)
        ->forProperties(collect([$t->a->property, $t->a->otherProperty]), '2026-12-10', '2026-12-12'))['operations'];

    expect($operations['daily'])->toEqual([
        ['date' => '2026-12-10', 'arrivals' => 2, 'departures' => 0, 'inHouse' => 0],
        ['date' => '2026-12-11', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 0],
        ['date' => '2026-12-12', 'arrivals' => 0, 'departures' => 2, 'inHouse' => 0],
    ])->and(collect($operations['housekeeping'])->pluck('rooms', 'status')->all())->toEqual([
        'dirty' => 1, 'clean' => 1, 'occupied' => 0, 'inspection_required' => 0, 'unavailable' => 0,
    ]);
});

test('group finance adds full per-currency totals and never sums a page of folios', function () {
    $t = twoTenants();
    $t->a->property->update(['business_date' => '2026-12-10']);
    $t->a->otherProperty->update(['currency' => 'TZS', 'business_date' => '2026-12-10']);
    foreach ([[$t->a->property, 'USD', 4000], [$t->a->otherProperty, 'TZS', 50000]] as [$property, $currency, $amount]) {
        inTenant($property, function () use ($t, $currency, $amount) {
            $details = BookingForm::details($t->a->owner);
            for ($number = 2; $number <= 52; $number++) {
                app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($details['room_type_id'], ['number' => (string) $number]));
            }
            foreach (range(1, 52) as $ignored) {
                $reservation = app(CreateReservation::class)->handle($t->a->owner, $details);
                $folio = $currency === 'USD' ? Folio::query()->where('reservation_id', $reservation->id)->sole() : app(OpenFolio::class)->handle($t->a->owner, $reservation, 'TZS');
                app(PostExtraCharge::class)->handle($t->a->owner, $folio, ['category' => 'minibar', 'quantity' => 1, 'unit_price' => $amount]);
            }
            app(RecordPayment::class)->handle($t->a->owner, $folio, ['method' => 'card', 'amount' => $amount, 'currency' => $currency]);
            Reservation::query()->orderBy('id')->limit(2)->update(['status' => ReservationStatus::CheckedOut]);
        });
    }
    $report = inTenant($t->a->organization, fn () => app(GroupReports::class)
        ->forProperties(collect([$t->a->property, $t->a->otherProperty]), '2026-12-10', '2026-12-10'));

    expect($report['finance']['payments']['totals'])->toEqual([
        ['currency' => 'TZS', 'payments' => 50000, 'refunds' => 0, 'reversals' => 0, 'net' => 50000],
        ['currency' => 'USD', 'payments' => 4000, 'refunds' => 0, 'reversals' => 0, 'net' => 4000],
    ])->and($report['finance']['openAfterDeparture'])->toEqual([
        ['currency' => 'TZS', 'folios' => 2, 'balance' => 100000],
        ['currency' => 'USD', 'folios' => 2, 'balance' => 8000],
    ])->and(collect($report['finance']['payments']['rows'])->pluck('propertyId')->all())->toEqual(
        collect([$t->a->otherProperty, $t->a->property])->sortBy('name')->pluck('id')->all(),
    )->and(collect($report['properties'])->pluck('unsettledFolios', 'id')->all())->toEqual([
        $t->a->property->id => 51,
        $t->a->otherProperty->id => 51,
    ]);
});

test('a property context still reads every included property of the organization', function () {
    $t = twoTenants();
    foreach ([$t->a->property, $t->a->otherProperty] as $property) {
        inTenant($property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    }

    $kpis = inTenant($t->a->property, fn () => app(GroupReports::class)
        ->forProperties(collect([$t->a->property, $t->a->otherProperty]), '2026-12-10', '2026-12-11'))['kpis'];

    expect($kpis['soldRoomNights'])->toBe(4)->and($kpis['currencies'][0]['bookedRoomValue'])->toBe(44000);
});

test('group finance preserves credit balances settled folios and dated refund reversals from property reports', function () {
    $t = twoTenants();
    $t->a->property->update(['business_date' => '2026-12-10']);
    $t->a->otherProperty->update(['business_date' => '2026-12-10']);
    foreach ([$t->a->property, $t->a->otherProperty] as $property) {
        inTenant($property, function () use ($t, $property) {
            $reservation = app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
            $folio = Folio::query()->where('reservation_id', $reservation->id)->sole();
            $settled = app(OpenFolio::class)->handle($t->a->owner, $reservation, 'TZS');
            app(PostExtraCharge::class)->handle($t->a->owner, $settled, ['category' => 'minibar', 'quantity' => 1, 'unit_price' => 100]);
            app(RecordPayment::class)->handle($t->a->owner, $settled, ['method' => 'card', 'amount' => 100, 'currency' => 'TZS']);
            app(RecordPayment::class)->handle($t->a->owner, $folio, ['method' => 'card', 'amount' => 2000, 'currency' => 'USD']);
            $refund = app(RecordAdjustment::class)->handle($t->a->owner, $folio, 'refund', [
                'amount' => 500, 'reason' => 'Overpayment', 'idempotency_key' => 'group-refund', 'method' => 'card',
            ]);
            $property->update(['business_date' => '2026-12-11']);
            app(ReversePosting::class)->handle($t->a->owner, $refund, 'Not sent');
            $reservation->update(['status' => ReservationStatus::CheckedOut]);
        });
    }
    $report = inTenant($t->a->property, fn () => app(GroupReports::class)
        ->forProperties(collect([$t->a->property, $t->a->otherProperty]), '2026-12-11', '2026-12-11'));

    expect(array_column($report['properties'], 'unsettledFolios'))->toBe([1, 1])
        ->and($report['finance']['openAfterDeparture'])->toEqual([['currency' => 'USD', 'folios' => 2, 'balance' => -4000]])
        ->and($report['finance']['payments']['totals'])->toEqual([
            ['currency' => 'USD', 'payments' => 0, 'refunds' => 0, 'reversals' => 1000, 'net' => 1000],
        ]);
    foreach ([$t->a->property, $t->a->otherProperty] as $property) {
        $finance = inTenant($property, fn () => app(PropertyFinance::class)->forProperty($property, '2026-12-11', '2026-12-11'));
        $row = collect($report['finance']['payments']['rows'])->firstWhere('propertyId', $property->id);
        expect(collect($row)->except(['propertyId', 'propertyName', 'timezone'])->all())->toEqual($finance['payments']['rows'][0]);
    }
});

test('group KPI currency denominators follow price line currencies rather than the property currency', function () {
    $t = twoTenants();
    $t->a->otherProperty->update(['currency' => 'TZS']);
    foreach ([$t->a->property, $t->a->otherProperty] as $property) {
        inTenant($property, function () use ($t) {
            app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
            ReservationPriceLine::query()->whereDate('date', '2026-12-11')->update(['currency' => 'EUR', 'amount' => 11999]);
        });
    }

    $report = inTenant($t->a->property, fn () => app(GroupReports::class)
        ->forProperties(collect([$t->a->property, $t->a->otherProperty]), '2026-12-10', '2026-12-11'));

    expect($report['kpis'])->toEqual([
        'soldRoomNights' => 4, 'sellableRoomNights' => 4, 'occupancy' => 1,
        'currencies' => [
            ['currency' => 'EUR', 'bookedRoomValue' => 23998, 'soldRoomNights' => 4, 'sellableRoomNights' => 4, 'bookedAdr' => 6000, 'bookedRevpar' => 6000],
            ['currency' => 'USD', 'bookedRoomValue' => 22000, 'soldRoomNights' => 4, 'sellableRoomNights' => 4, 'bookedAdr' => 5500, 'bookedRevpar' => 5500],
        ],
    ]);
});
