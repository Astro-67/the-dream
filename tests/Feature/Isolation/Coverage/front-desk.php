<?php

use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use Tests\Support\BookingForm;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

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
    'reservations.add-guest' => RouteCoverage::attempt(function (TwoTenants $t) {
        $reservation = inTenant($t->b->property, function () use ($t) {
            $reservation = app(CreateReservation::class)->handle($t->b->owner, BookingForm::details($t->b->owner));
            $reservation->update(['status' => ReservationStatus::CheckedIn]);

            return $reservation;
        });
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->post(route('reservations.add-guest', $reservation), ['_property' => $t->a->property->id, 'name' => 'Hijacked'])->assertNotFound();
        }
        inTenant($t->b->property, fn () => expect(ReservationGuest::query()->count())->toBe(0)
            ->and($reservation->lifecycleEvents()->where('type', 'guest_added')->exists())->toBeFalse());

        return $responses;
    }),
];
