<?php

use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Reports\PropertyKpis;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\ReservationPriceLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BookingForm;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('property KPIs allocate booked value to the selected nights and read inventory counts', function () {
    [$owner, , $property] = organizationWithOwner();

    inTenant($property, function () use ($property, $owner) {
        app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
        InventoryRecord::query()->whereDate('date', '2026-12-10')->update(['sellable_count' => 4]);
        InventoryRecord::query()->whereDate('date', '2026-12-11')->update(['sellable_count' => 6]);

        expect(app(PropertyKpis::class)->forProperty($property, '2026-12-10', '2026-12-11'))->toEqual([
            'soldRoomNights' => 2,
            'sellableRoomNights' => 10,
            'occupancy' => 0.2,
            'currencies' => [
                ['currency' => 'USD', 'bookedRoomValue' => 22000, 'bookedAdr' => 11000, 'bookedRevpar' => 2200],
            ],
        ]);
    });
});

test('empty periods retain the property currency and mark zero denominators not applicable', function () {
    [, , $property] = organizationWithOwner(['currency' => 'TZS']);

    expect(inTenant($property, fn () => app(PropertyKpis::class)->forProperty($property, '2026-12-10', '2026-12-11')))
        ->toEqual([
            'soldRoomNights' => 0,
            'sellableRoomNights' => 0,
            'occupancy' => null,
            'currencies' => [
                ['currency' => 'TZS', 'bookedRoomValue' => 0, 'bookedAdr' => null, 'bookedRevpar' => null],
            ],
        ]);
});

test('booked values keep currencies separate exclude terminal reservations and round money in minor units', function () {
    [$owner, , $property] = organizationWithOwner();

    inTenant($property, function () use ($property, $owner) {
        $reservation = app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
        $reservation->priceLines()->whereDate('date', '2026-12-11')->update(['amount' => 11999, 'currency' => 'EUR']);
        InventoryRecord::query()->whereDate('date', '>=', '2026-12-10')->whereDate('date', '<=', '2026-12-11')->update(['sellable_count' => 2]);
        foreach ([ReservationStatus::Cancelled, ReservationStatus::NoShow, ReservationStatus::Void] as $status) {
            $excluded = $reservation->replicate();
            $excluded->fill(['reservation_number' => 'EXCLUDED-'.$status->value, 'status' => $status])->save();
            ReservationPriceLine::query()->create(['reservation_id' => $excluded->id, 'rate_plan_id' => $reservation->getAttribute('rate_plan_id'), 'date' => '2026-12-10', 'amount' => 999999, 'currency' => 'TZS']);
        }

        // RPT-02 divides by all sold room-nights, which inventory does not split by currency:
        // each currency's ADR is its share per room-night (EUR 119.99 / 2 nights), not per EUR night.
        expect(app(PropertyKpis::class)->forProperty($property, '2026-12-10', '2026-12-11')['currencies'])->toEqual([
            ['currency' => 'EUR', 'bookedRoomValue' => 11999, 'bookedAdr' => 6000, 'bookedRevpar' => 3000],
            ['currency' => 'USD', 'bookedRoomValue' => 11000, 'bookedAdr' => 5500, 'bookedRevpar' => 2750],
        ]);
        expect(app(PropertyKpis::class)->forProperty($property, '2026-12-11', '2026-12-11')['currencies'])->toEqual([
            ['currency' => 'EUR', 'bookedRoomValue' => 11999, 'bookedAdr' => 11999, 'bookedRevpar' => 6000],
        ]);
    });
});

test('unsold inventory has zero occupancy and RevPAR but ADR is not applicable', function () {
    [$owner, , $property] = organizationWithOwner();

    inTenant($property, function () use ($property, $owner) {
        BookingForm::details($owner);

        expect(app(PropertyKpis::class)->forProperty($property, '2026-12-10', '2026-12-11'))->toEqual([
            'soldRoomNights' => 0,
            'sellableRoomNights' => 2,
            'occupancy' => 0,
            'currencies' => [
                ['currency' => 'USD', 'bookedRoomValue' => 0, 'bookedAdr' => null, 'bookedRevpar' => 0],
            ],
        ]);
    });
});

test('an organization context still reads only the requested property', function () {
    $t = twoTenants();
    foreach ([$t->a->property, $t->a->otherProperty, $t->b->property] as $property) {
        $owner = $property->organization_id === $t->a->organization->id ? $t->a->owner : $t->b->owner;
        inTenant($property, fn () => app(CreateReservation::class)->handle($owner, BookingForm::details($owner)));
    }

    $kpis = inTenant($t->a->organization, fn () => app(PropertyKpis::class)->forProperty($t->a->property, '2026-12-10', '2026-12-11'));

    expect($kpis['soldRoomNights'])->toBe(2)
        ->and($kpis['sellableRoomNights'])->toBe(2)
        ->and($kpis['currencies'])->toEqual([
            ['currency' => 'USD', 'bookedRoomValue' => 22000, 'bookedAdr' => 11000, 'bookedRevpar' => 11000],
        ]);
});
