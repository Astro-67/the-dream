<?php

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Reports\PropertyOperations;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('daily operations follow reservation lists and include both range boundaries', function () {
    [$owner, , $property] = organizationWithOwner();

    inTenant($property, function () use ($owner, $property) {
        $reservation = app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
        foreach (ReservationStatus::cases() as $status) {
            if ($status === ReservationStatus::Confirmed) {
                continue;
            }
            $copy = $reservation->replicate();
            $copy->fill(['reservation_number' => 'STATUS-'.$status->value, 'status' => $status])->save();
        }

        expect(app(PropertyOperations::class)->forProperty($property, '2026-12-09', '2026-12-12')['daily'])->toEqual([
            ['date' => '2026-12-09', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 0],
            ['date' => '2026-12-10', 'arrivals' => 2, 'departures' => 0, 'inHouse' => 1],
            ['date' => '2026-12-11', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 1],
            ['date' => '2026-12-12', 'arrivals' => 0, 'departures' => 3, 'inHouse' => 0],
        ]);
        expect(app(PropertyOperations::class)->forProperty($property, '2026-12-12', '2026-12-12')['daily'])
            ->toEqual([['date' => '2026-12-12', 'arrivals' => 0, 'departures' => 3, 'inHouse' => 0]]);
    });
});

test('housekeeping counts current active rooms in every status regardless of the selected dates', function () {
    [$owner, , $property] = organizationWithOwner();

    inTenant($property, function () use ($owner, $property) {
        $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType());
        foreach (HousekeepingStatus::cases() as $index => $status) {
            $room = app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id, ['number' => (string) (101 + $index)]));
            RoomStatus::query()->where('room_id', $room->id)->update(['status' => $status]);
        }
        $room = app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id, ['number' => 'inactive']));
        $room->forceFill(['deactivated_at' => now()])->save();

        $expected = [
            ['status' => 'dirty', 'label' => 'Dirty', 'tone' => 'warning', 'rooms' => 1],
            ['status' => 'clean', 'label' => 'Clean', 'tone' => 'success', 'rooms' => 1],
            ['status' => 'occupied', 'label' => 'Occupied', 'tone' => 'occupied', 'rooms' => 1],
            ['status' => 'inspection_required', 'label' => 'Inspection required', 'tone' => 'inspection', 'rooms' => 1],
            ['status' => 'unavailable', 'label' => 'Unavailable', 'tone' => 'unavailable', 'rooms' => 1],
        ];
        expect(app(PropertyOperations::class)->forProperty($property, '2025-01-01', '2025-01-01')['housekeeping'])->toEqual($expected)
            ->and(app(PropertyOperations::class)->forProperty($property, '2027-01-01', '2027-01-01')['housekeeping'])->toEqual($expected);
    });
});

test('an organization context counts only the requested property and empty dates remain zero', function () {
    $t = twoTenants();
    foreach ([$t->a->property, $t->a->otherProperty, $t->b->property] as $property) {
        $owner = $property->organization_id === $t->a->organization->id ? $t->a->owner : $t->b->owner;
        inTenant($property, function () use ($owner) {
            $reservation = app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
            $reservation->update(['status' => ReservationStatus::CheckedIn]);
        });
    }

    $operations = inTenant($t->a->organization, fn () => app(PropertyOperations::class)
        ->forProperty($t->a->property, '2026-12-11', '2026-12-13'));

    expect($operations['daily'])->toEqual([
        ['date' => '2026-12-11', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 1],
        ['date' => '2026-12-12', 'arrivals' => 0, 'departures' => 1, 'inHouse' => 0],
        ['date' => '2026-12-13', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 0],
    ])->and(array_sum(array_column($operations['housekeeping'], 'rooms')))->toBe(1);
});

test('an empty property returns every selected business date and all housekeeping statuses with zero counts', function () {
    [, , $property] = organizationWithOwner();
    $operations = inTenant($property, fn () => app(PropertyOperations::class)
        ->forProperty($property, '2026-12-10', '2026-12-11'));

    expect($operations['daily'])->toEqual([
        ['date' => '2026-12-10', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 0],
        ['date' => '2026-12-11', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 0],
    ])->and(array_column($operations['housekeeping'], 'rooms'))->toBe([0, 0, 0, 0, 0]);
});
