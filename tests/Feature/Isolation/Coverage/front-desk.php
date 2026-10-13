<?php

use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;
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

    'reservations.room-move' => RouteCoverage::attempt(function (TwoTenants $t) {
        $this->travelTo('2026-12-10 09:00:00');
        [$reservation, $other] = inTenant($t->b->property, function () use ($t) {
            $details = BookingForm::details($t->b->owner);
            $room = Room::query()->sole();
            $other = app(CreateRoom::class)->handle($t->b->owner, InventoryForm::room($details['room_type_id'], ['number' => '102']));
            RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
            $reservation = app(CreateReservation::class)->handle($t->b->owner, [...$details, 'room_id' => $room->id]);
            $reservation->update(['status' => ReservationStatus::CheckedIn]);

            return [$reservation, $other];
        });
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->post(route('reservations.room-move', $reservation), ['_property' => $t->a->property->id, 'room_id' => $other->id])->assertNotFound();
        }
        inTenant($t->b->property, function () use ($reservation) {
            expect($reservation->fresh()->room_id)->toBe($reservation->room_id)
                ->and($reservation->lifecycleEvents()->where('type', 'room_moved')->exists())->toBeFalse()
                ->and(RoomStatus::query()->pluck('status')->unique()->all())->toBe([HousekeepingStatus::Clean]);
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
