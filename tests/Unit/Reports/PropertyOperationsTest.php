<?php

use App\Domains\Reports\PropertyOperations;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BookingForm;
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
