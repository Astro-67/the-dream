<?php

use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Reports\PropertyKpis;
use App\Domains\Reservations\Actions\CreateReservation;
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
