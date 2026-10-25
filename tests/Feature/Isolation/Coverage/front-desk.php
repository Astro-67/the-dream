<?php

use App\Domains\Billing\Models\Posting;
use App\Domains\FrontDesk\Actions\CloseBusinessDate;
use App\Domains\FrontDesk\Models\BusinessDateClose;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\NoShowProposalStatus;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\NoShowProposal;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

// Alpha's members try to confirm or reject Bravo's no-show proposal.
$attackNoShow = fn (string $route) => function (TwoTenants $t) use ($route) {
    $t->b->property->update(['business_date' => '2026-12-10']);
    $proposal = inTenant($t->b->property, function () use ($t) {
        app(CreateReservation::class)->handle($t->b->owner, BookingForm::details($t->b->owner));
        app(CloseBusinessDate::class)->handle(null, $t->b->property, '2026-12-10');

        return NoShowProposal::query()->sole();
    });
    $responses = [];
    foreach ($t->a->members() as $member) {
        $responses[] = $this->actingAs($member)->post(route($route, $proposal), ['_property' => $t->a->property->id])->assertNotFound();
    }
    inTenant($t->b->property, fn () => expect($proposal->fresh()->status)->toBe(NoShowProposalStatus::Proposed)
        ->and($proposal->reservation->status)->toBe(ReservationStatus::Confirmed));

    return $responses;
};

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
    'night-audit.index' => RouteCoverage::visit(),
    'night-audit.show' => RouteCoverage::attempt(function (TwoTenants $t) {
        $close = inTenant($t->b->property, fn () => BusinessDateClose::query()->create([
            'business_date' => '2026-12-10', 'actor_id' => $t->b->owner->id, 'completed_at' => now(),
        ]));
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->get(route('night-audit.show', $close))->assertNotFound();
        }

        return $responses;
    }),
    'night-audit.store' => RouteCoverage::attempt(function (TwoTenants $t) {
        $date = $t->b->property->business_date->toDateString();
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->post(route('night-audit.store'), [
                '_property' => $t->b->property->id, 'business_date' => $date,
            ])->assertForbidden();
        }
        inTenant($t->b->property, fn () => expect(BusinessDateClose::query()->count())->toBe(0)
            ->and(Posting::query()->count())->toBe(0)
            ->and($t->b->property->fresh()->business_date->toDateString())->toBe($date));

        return $responses;
    }),
    'no-show-proposals.confirm' => RouteCoverage::attempt($attackNoShow('no-show-proposals.confirm')),
    'no-show-proposals.reject' => RouteCoverage::attempt($attackNoShow('no-show-proposals.reject')),
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

    'reservations.guest-leaves' => RouteCoverage::attempt(function (TwoTenants $t) {
        [$reservation, $link] = inTenant($t->b->property, function () use ($t) {
            $reservation = app(CreateReservation::class)->handle($t->b->owner, BookingForm::details($t->b->owner));
            $reservation->update(['status' => ReservationStatus::CheckedIn]);
            foreach (['Lead Bravo', 'Second Bravo'] as $name) {
                $link = ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => app(CreateGuest::class)->handle($t->b->owner, ['name' => $name])->id]);
            }

            return [$reservation, $link];
        });
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->post(route('reservations.guest-leaves', $reservation), ['_property' => $t->a->property->id, 'guest_id' => $link->guest_id])->assertNotFound();
        }
        inTenant($t->b->property, fn () => expect($link->fresh()->departed_at)->toBeNull()
            ->and($reservation->lifecycleEvents()->where('type', 'guest_departed')->exists())->toBeFalse());

        return $responses;
    }),

    'reservations.check-out' => RouteCoverage::attempt(function (TwoTenants $t) {
        $this->travelTo('2026-12-10 09:00:00');
        $reservation = inTenant($t->b->property, function () use ($t) {
            $reservation = app(CreateReservation::class)->handle($t->b->owner, [...BookingForm::details($t->b->owner), 'room_id' => Room::query()->sole()->id]);
            RoomStatus::query()->update(['status' => HousekeepingStatus::Occupied]);
            $reservation->update(['status' => ReservationStatus::CheckedIn]);

            return $reservation;
        });
        $this->travelTo('2026-12-12 09:00:00');
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->post(route('reservations.check-out', $reservation), ['_property' => $t->a->property->id, 'override_reason' => 'Hijacked'])->assertNotFound();
        }
        inTenant($t->b->property, function () use ($reservation) {
            expect($reservation->fresh()->status)->toBe(ReservationStatus::CheckedIn)
                ->and($reservation->lifecycleEvents()->where('type', 'checked_out')->exists())->toBeFalse()
                ->and(RoomStatus::query()->sole()->status)->toBe(HousekeepingStatus::Occupied);
        });

        return $responses;
    }),

    'reservations.extend' => RouteCoverage::attempt($attackStayLength('reservations.extend')),
    'reservations.depart-early' => RouteCoverage::attempt($attackStayLength('reservations.depart-early')),
];
