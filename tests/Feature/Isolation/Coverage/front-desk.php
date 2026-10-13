<?php

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use Tests\Support\BookingForm;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

// Alpha's members try to change the length of Bravo's in-house stay.
$attackStayLength = fn (string $route) => function (TwoTenants $t) use ($route) {
    $this->travelTo('2026-12-10 09:00:00');
    $reservation = inTenant($t->b->property, function () use ($t) {
        $details = BookingForm::details($t->b->owner);
        $reservation = app(CreateReservation::class)->handle($t->b->owner, [...$details, 'room_id' => Room::query()->sole()->id]);
        $reservation->update(['status' => ReservationStatus::CheckedIn]);

        return $reservation;
    });
    $this->travelTo('2026-12-11 09:00:00');
    $responses = [];
    foreach ($t->a->members() as $member) {
        $responses[] = $this->actingAs($member)->post(route($route, $reservation), ['_property' => $t->a->property->id, 'departure' => '2026-12-13'])->assertNotFound();
    }
    inTenant($t->b->property, function () use ($reservation) {
        expect($reservation->fresh()->departure->toDateString())->toBe('2026-12-12')
            ->and($reservation->lifecycleEvents()->count())->toBe(1);
    });

    return $responses;
};

return [
    'reservations.check-in' => RouteCoverage::attempt(function (TwoTenants $t) {
        $this->travelTo('2026-12-10 09:00:00');
        $reservation = inTenant($t->b->property, function () use ($t) {
            $details = BookingForm::details($t->b->owner);
            $room = Room::query()->sole();
            RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);

            return app(CreateReservation::class)->handle($t->b->owner, [...$details, 'room_id' => $room->id]);
        });
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->post(route('reservations.check-in', $reservation), ['_property' => $t->a->property->id])->assertNotFound();
        }
        inTenant($t->b->property, function () use ($reservation) {
            expect($reservation->fresh()->status)->toBe(ReservationStatus::Confirmed)
                ->and($reservation->lifecycleEvents()->count())->toBe(1)
                ->and(RoomStatus::query()->sole()->status)->toBe(HousekeepingStatus::Clean);
        });

        return $responses;
    }),
    'reservations.extend' => RouteCoverage::attempt($attackStayLength('reservations.extend')),
    'reservations.depart-early' => RouteCoverage::attempt($attackStayLength('reservations.depart-early')),
];
