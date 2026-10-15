<?php

use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Reports\GroupReports;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\ReservationPriceLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BookingForm;
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
