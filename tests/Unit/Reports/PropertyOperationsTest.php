<?php

use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\FrontDesk\Actions\CheckOut;
use App\Domains\FrontDesk\Actions\DepartEarly;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reports\PropertyOperations;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('unrecorded legacy stays are flagged rather than silently inferred and plans stay separate', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['business_date' => '2026-12-10']);

    inTenant($property, function () use ($owner, $property) {
        $reservation = app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
        foreach (ReservationStatus::cases() as $status) {
            if ($status === ReservationStatus::Confirmed) {
                continue;
            }
            $copy = $reservation->replicate();
            $copy->fill(['reservation_number' => 'STATUS-'.$status->value, 'status' => $status])->save();
        }

        $operations = app(PropertyOperations::class)->forProperty($property, '2026-12-09', '2026-12-12');
        expect($operations['historyUnavailable'])->toBe(2)
            ->and($operations['daily'])->toEqual([
                ['date' => '2026-12-09', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 0, 'plannedArrivals' => 0, 'plannedDepartures' => 0],
                ['date' => '2026-12-10', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 0, 'plannedArrivals' => 1, 'plannedDepartures' => 0],
                ['date' => '2026-12-11', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 0, 'plannedArrivals' => 0, 'plannedDepartures' => 0],
                ['date' => '2026-12-12', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 0, 'plannedArrivals' => 0, 'plannedDepartures' => 2],
            ]);
        expect(app(PropertyOperations::class)->forProperty($property, '2026-12-12', '2026-12-12')['daily'])
            ->toEqual([['date' => '2026-12-12', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 0, 'plannedArrivals' => 0, 'plannedDepartures' => 2]]);
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
        ['date' => '2026-12-11', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 0, 'plannedArrivals' => 0, 'plannedDepartures' => 0],
        ['date' => '2026-12-12', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 0, 'plannedArrivals' => 0, 'plannedDepartures' => 1],
        ['date' => '2026-12-13', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 0, 'plannedArrivals' => 0, 'plannedDepartures' => 0],
    ])->and($operations['historyUnavailable'])->toBe(1)
        ->and(array_sum(array_column($operations['housekeeping'], 'rooms')))->toBe(1);
});

test('an empty property returns every selected business date and all housekeeping statuses with zero counts', function () {
    [, , $property] = organizationWithOwner();
    $operations = inTenant($property, fn () => app(PropertyOperations::class)
        ->forProperty($property, '2026-12-10', '2026-12-11'));

    expect($operations['daily'])->toEqual([
        ['date' => '2026-12-10', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 0, 'plannedArrivals' => 0, 'plannedDepartures' => 0],
        ['date' => '2026-12-11', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 0, 'plannedArrivals' => 0, 'plannedDepartures' => 0],
    ])->and(array_column($operations['housekeeping'], 'rooms'))->toBe([0, 0, 0, 0, 0]);
});

test('identical legacy stays retain their unknown history and planned departures', function () {
    [$owner, , $property] = organizationWithOwner();

    inTenant($property, function () use ($owner, $property) {
        $reservation = app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
        $reservation->update(['arrival' => '2026-10-01', 'departure' => '2027-01-01', 'status' => ReservationStatus::CheckedIn]);
        $copy = $reservation->replicate();
        $copy->fill(['reservation_number' => 'SECOND-STAY'])->save();

        expect(app(PropertyOperations::class)->forProperty($property, '2026-12-31', '2027-01-02')['daily'])->toEqual([
            ['date' => '2026-12-31', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 0, 'plannedArrivals' => 0, 'plannedDepartures' => 0],
            ['date' => '2027-01-01', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 0, 'plannedArrivals' => 0, 'plannedDepartures' => 2],
            ['date' => '2027-01-02', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 0, 'plannedArrivals' => 0, 'plannedDepartures' => 0],
        ]);
    });
});

test('normal and early check-out retain recorded arrival and in-house nights at inclusive range endpoints', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['business_date' => '2026-12-10']);

    inTenant($property, function () use ($owner, $property) {
        $details = BookingForm::details($owner);
        app(CreateRoom::class)->handle($owner, InventoryForm::room($details['room_type_id'], ['number' => '102']));
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        $stays = [];
        foreach (Room::query()->orderBy('number')->get() as $index => $room) {
            $reservation = app(CreateReservation::class)->handle($owner, [...$details, 'room_id' => $room->id]);
            $guest = app(CreateGuest::class)->handle($owner, ['name' => 'Guest '.$index, 'documents' => [['type' => 'passport', 'number' => 'P123456'.$index, 'nationality' => 'DE']]]);
            ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);
            $stays[] = app(CheckIn::class)->handle($owner, $reservation);
        }
        [$full, $early] = $stays;
        $property->update(['business_date' => '2026-12-11']);
        app(DepartEarly::class)->handle($owner, $early);
        app(CheckOut::class)->handle($owner, $early->fresh());
        $property->update(['business_date' => '2026-12-12']);
        app(CheckOut::class)->handle($owner, $full->fresh());

        expect(app(PropertyOperations::class)->forProperty($property, '2026-12-09', '2026-12-12')['daily'])->toEqual([
            ['date' => '2026-12-09', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 0, 'plannedArrivals' => 0, 'plannedDepartures' => 0],
            ['date' => '2026-12-10', 'arrivals' => 2, 'departures' => 0, 'inHouse' => 2, 'plannedArrivals' => 0, 'plannedDepartures' => 0],
            ['date' => '2026-12-11', 'arrivals' => 0, 'departures' => 1, 'inHouse' => 1, 'plannedArrivals' => 0, 'plannedDepartures' => 0],
            ['date' => '2026-12-12', 'arrivals' => 0, 'departures' => 1, 'inHouse' => 0, 'plannedArrivals' => 0, 'plannedDepartures' => 0],
        ]);
    });
});

test('delayed check-out uses its recorded business date rather than scheduled departure', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['business_date' => '2026-12-10']);

    inTenant($property, function () use ($owner, $property) {
        $details = BookingForm::details($owner);
        $reservation = app(CreateReservation::class)->handle($owner, [...$details, 'room_id' => Room::query()->sole()->id]);
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        $guest = app(CreateGuest::class)->handle($owner, ['name' => 'Delayed check-out guest', 'documents' => [['type' => 'passport', 'number' => 'P1234567', 'nationality' => 'DE']]]);
        ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);
        $reservation = app(CheckIn::class)->handle($owner, $reservation);
        $property->update(['business_date' => '2026-12-13']);
        $checkedOut = app(CheckOut::class)->handle($owner, $reservation);

        expect($checkedOut->status)->toBe(ReservationStatus::CheckedOut)
            ->and($checkedOut->departure->toDateString())->toBe('2026-12-12')
            ->and(app(PropertyOperations::class)->forProperty($property, '2026-12-10', '2026-12-13')['daily'])->toEqual([
                ['date' => '2026-12-10', 'arrivals' => 1, 'departures' => 0, 'inHouse' => 1, 'plannedArrivals' => 0, 'plannedDepartures' => 0],
                ['date' => '2026-12-11', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 1, 'plannedArrivals' => 0, 'plannedDepartures' => 0],
                ['date' => '2026-12-12', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 1, 'plannedArrivals' => 0, 'plannedDepartures' => 0],
                ['date' => '2026-12-13', 'arrivals' => 0, 'departures' => 1, 'inHouse' => 0, 'plannedArrivals' => 0, 'plannedDepartures' => 0],
            ]);
    });
});
