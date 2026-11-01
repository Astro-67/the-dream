<?php

use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\FrontDesk\Actions\CheckOut;
use App\Domains\FrontDesk\Actions\ExtendStay;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Rates\Actions\SetRatePrices;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Reports\GroupReports;
use App\Domains\Reports\PropertyOperations;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Actions\CreateDemoProperty;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;

function recordedOperationsStay(User $owner, Property $property): Reservation
{
    $property->update(['business_date' => '2026-12-10']);

    return inTenant($property, function () use ($owner): Reservation {
        $details = BookingForm::details($owner);
        $room = Room::query()->where('room_type_id', $details['room_type_id'])->sole();
        RoomStatus::query()->where('room_id', $room->id)->update(['status' => HousekeepingStatus::Clean]);
        $reservation = app(CreateReservation::class)->handle($owner, [...$details, 'adults' => 1, 'children' => 0, 'room_id' => $room->id]);
        $guest = app(CreateGuest::class)->handle($owner, ['name' => 'Recorded stay guest', 'documents' => [
            ['type' => 'passport', 'number' => 'P1234567', 'nationality' => 'DE'],
        ]]);
        ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);

        return app(CheckIn::class)->handle($owner, $reservation);
    });
}

test('planned arrivals include the selected final business date without becoming recorded history', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['business_date' => '2026-12-10']);
    inTenant($property, fn () => app(CreateReservation::class)->handle($owner, BookingForm::details($owner)));

    $operations = inTenant($property, fn () => app(PropertyOperations::class)->forProperty($property, '2026-12-10', '2026-12-10'));
    expect($operations['daily'])->toEqual([
        ['date' => '2026-12-10', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 0, 'plannedArrivals' => 1, 'plannedDepartures' => 0],
    ]);
});

test('HTTP operations retain delayed check-out history even when the range starts after scheduled departure', function () {
    [$owner, , $property] = organizationWithOwner();
    $this->travelTo('2027-01-15 23:30:00 UTC');
    $reservation = recordedOperationsStay($owner, $property);
    $property->update(['business_date' => '2026-12-13']);
    inTenant($property, fn () => app(CheckOut::class)->handle($owner, $reservation));

    $this->actingAs($owner)->get(route('reports.index', ['from' => '2026-12-13', 'to' => '2026-12-13']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('operations.daily', [
            ['date' => '2026-12-13', 'arrivals' => 0, 'departures' => 1, 'inHouse' => 0, 'plannedArrivals' => 0, 'plannedDepartures' => 0],
        ])
        ->where('operations.historyUnavailable', 0)
        ->where('operations.housekeeping.0.rooms', 1));

    $history = inTenant($property, fn () => app(PropertyOperations::class)->forProperty($property, '2026-12-12', '2026-12-12'));
    expect($history['daily'])->toEqual([
        ['date' => '2026-12-12', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 1, 'plannedArrivals' => 0, 'plannedDepartures' => 0],
    ]);
});

test('open recorded stays continue past scheduled departure only through the property business date', function () {
    [$owner, , $property] = organizationWithOwner();
    recordedOperationsStay($owner, $property);
    $property->update(['business_date' => '2026-12-13']);

    $operations = inTenant($property, fn () => app(PropertyOperations::class)->forProperty($property, '2026-12-12', '2026-12-14'));
    expect($operations['daily'])->toEqual([
        ['date' => '2026-12-12', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 1, 'plannedArrivals' => 0, 'plannedDepartures' => 0],
        ['date' => '2026-12-13', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 1, 'plannedArrivals' => 0, 'plannedDepartures' => 0],
        ['date' => '2026-12-14', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 0, 'plannedArrivals' => 0, 'plannedDepartures' => 0],
    ]);
});

test('extension changes planned departure without inventing future in-house history', function () {
    [$owner, , $property] = organizationWithOwner();
    $reservation = recordedOperationsStay($owner, $property);
    $property->update(['business_date' => '2026-12-11']);

    inTenant($property, function () use ($owner, $reservation): void {
        app(SetRatePrices::class)->handle($owner, RatePlan::query()->findOrFail($reservation->rate_plan_id), [
            'room_type_id' => $reservation->room_type_id, 'from' => '2026-12-12', 'to' => '2026-12-13',
            'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'base_price' => 10000, 'extra_adult' => 2500, 'extra_child' => 1000,
        ]);
        app(ExtendStay::class)->handle($owner, $reservation, '2026-12-14');
    });
    $operations = inTenant($property, fn () => app(PropertyOperations::class)->forProperty($property, '2026-12-10', '2026-12-14'));
    expect(array_column($operations['daily'], 'arrivals'))->toBe([1, 0, 0, 0, 0])
        ->and(array_column($operations['daily'], 'inHouse'))->toBe([1, 1, 0, 0, 0])
        ->and(array_column($operations['daily'], 'departures'))->toBe([0, 0, 0, 0, 0])
        ->and(array_column($operations['daily'], 'plannedDepartures'))->toBe([0, 0, 0, 0, 1]);

    $property->update(['business_date' => '2026-12-14']);
    inTenant($property, fn () => app(CheckOut::class)->handle($owner, $reservation->fresh()));
    $completed = inTenant($property, fn () => app(PropertyOperations::class)->forProperty($property, '2026-12-10', '2026-12-14'));
    expect(array_column($completed['daily'], 'inHouse'))->toBe([1, 1, 1, 1, 0])
        ->and(array_column($completed['daily'], 'departures'))->toBe([0, 0, 0, 0, 1])
        ->and(array_column($completed['daily'], 'plannedDepartures'))->toBe([0, 0, 0, 0, 0]);
});

test('group history uses each property business date independent of timezone or recording timestamp and excludes foreign and demo data', function () {
    $t = twoTenants();
    $this->travelTo('2027-01-15 23:30:00 UTC');
    $t->a->property->update(['timezone' => 'Pacific/Kiritimati']);
    $t->a->otherProperty->update(['timezone' => 'America/Los_Angeles']);
    $first = recordedOperationsStay($t->a->owner, $t->a->property);
    recordedOperationsStay($t->a->owner, $t->a->otherProperty);
    $foreign = recordedOperationsStay($t->b->owner, $t->b->property);
    $t->a->property->update(['business_date' => '2026-12-13']);
    $t->a->otherProperty->update(['business_date' => '2026-12-11']);
    $t->b->property->update(['business_date' => '2026-12-13']);
    inTenant($t->a->property, fn () => app(CheckOut::class)->handle($t->a->owner, $first));
    inTenant($t->b->property, fn () => app(CheckOut::class)->handle($t->b->owner, $foreign));
    $demo = inTenant($t->a->property, fn () => app(CreateDemoProperty::class)->handle($t->a->owner));

    $reports = inTenant($t->a->organization, fn () => app(GroupReports::class)->forProperties(
        collect([$t->a->property, $t->a->otherProperty, $t->b->property, $demo]), '2026-12-10', '2026-12-13',
    ));
    expect($reports['operations']['daily'])->toEqual([
        ['date' => '2026-12-10', 'arrivals' => 2, 'departures' => 0, 'inHouse' => 2, 'plannedArrivals' => 0, 'plannedDepartures' => 0],
        ['date' => '2026-12-11', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 2, 'plannedArrivals' => 0, 'plannedDepartures' => 0],
        ['date' => '2026-12-12', 'arrivals' => 0, 'departures' => 0, 'inHouse' => 1, 'plannedArrivals' => 0, 'plannedDepartures' => 1],
        ['date' => '2026-12-13', 'arrivals' => 0, 'departures' => 1, 'inHouse' => 0, 'plannedArrivals' => 0, 'plannedDepartures' => 0],
    ])->and($reports['operations']['historyUnavailable'])->toBe(0)
        ->and(array_column($reports['properties'], 'id'))->toEqualCanonicalizing([$t->a->property->id, $t->a->otherProperty->id]);

    $this->actingAs($t->a->owner)
        ->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->get(route('reports.group', ['from' => '2026-12-10', 'to' => '2026-12-13']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('operations.daily', $reports['operations']['daily'])
        ->where('operations.historyUnavailable', 0));
});

test('legacy completed stays without events expose unknown history in HTTP instead of scheduled actuals', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['business_date' => '2026-12-10']);
    inTenant($property, function () use ($owner): void {
        $reservation = app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
        $reservation->update(['status' => ReservationStatus::CheckedOut, 'imported' => true]);
    });
    $property->update(['business_date' => '2026-12-13']);

    $this->actingAs($owner)->get(route('reports.index', ['from' => '2026-12-10', 'to' => '2026-12-12']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('operations.historyUnavailable', 1)
        ->where('operations.daily.0.arrivals', 0)
        ->where('operations.daily.0.inHouse', 0)
        ->where('operations.daily.2.departures', 0));
});

test('recorded operations query count stays constant across multiple properties', function () {
    $t = twoTenants();
    recordedOperationsStay($t->a->owner, $t->a->property);
    recordedOperationsStay($t->a->owner, $t->a->otherProperty);

    inTenant($t->a->organization, function () use ($t): void {
        DB::enableQueryLog();
        app(PropertyOperations::class)->forProperties(collect([$t->a->property]), '2026-12-10', '2026-12-13');
        $oneProperty = count(DB::getQueryLog());
        DB::flushQueryLog();
        app(PropertyOperations::class)->forProperties(collect([$t->a->property, $t->a->otherProperty]), '2026-12-10', '2026-12-13');
        $twoProperties = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();
        expect($twoProperties)->toBe($oneProperty)->toBeLessThanOrEqual(3);
    });
});

test('partial legacy history retains only available recorded boundaries', function (bool $missingCheckIn) {
    [$owner, , $property] = organizationWithOwner();
    if ($missingCheckIn) {
        $reservation = inTenant($property, function () use ($owner): Reservation {
            $reservation = app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
            $reservation->update(['status' => ReservationStatus::CheckedIn, 'imported' => true]);

            return $reservation;
        });
        $property->update(['business_date' => '2026-12-13']);
        inTenant($property, fn () => app(CheckOut::class)->handle($owner, $reservation));
    } else {
        $reservation = recordedOperationsStay($owner, $property);
        inTenant($property, fn () => $reservation->update(['status' => ReservationStatus::CheckedOut, 'imported' => true]));
        $property->update(['business_date' => '2026-12-13']);
    }

    $operations = inTenant($property, fn () => app(PropertyOperations::class)->forProperty($property, '2026-12-10', '2026-12-13'));
    expect($operations['historyUnavailable'])->toBe(1)
        ->and(array_column($operations['daily'], 'inHouse'))->toBe([0, 0, 0, 0])
        ->and(array_column($operations['daily'], 'arrivals'))->toBe($missingCheckIn ? [0, 0, 0, 0] : [1, 0, 0, 0])
        ->and(array_column($operations['daily'], 'departures'))->toBe($missingCheckIn ? [0, 0, 0, 1] : [0, 0, 0, 0]);
})->with([true, false]);
