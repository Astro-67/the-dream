<?php

use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use Inertia\Testing\AssertableInertia;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

return [
    'reservations.index' => RouteCoverage::attempt(function (TwoTenants $t) {
        $reservation = inTenant($t->b->property, function () use ($t) {
            $reservation = app(CreateReservation::class)->handle($t->b->owner, [...BookingForm::details($t->b->owner), 'source' => 'outside-integration', 'channel_reference' => 'Bravo Private Reference', 'outside_integration_reason' => 'Fallback email']);
            $reservation->update(['reservation_number' => 'BRAVO-LOOKUP-000001']);
            $guest = Guest::query()->create(['name' => 'Bravo Private Guest']);
            ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);

            return $reservation;
        });
        $responses = [];
        foreach ($t->a->members() as $member) {
            foreach ([$reservation->reservation_number, 'Bravo Private Reference', 'Bravo Private Guest'] as $search) {
                $response = $this->actingAs($member)->get(route('reservations.index', ['list' => 'all', 'search' => $search]));
                if ($response->status() === 200) {
                    $response->assertInertia(fn (AssertableInertia $page) => $page->has('reservations.data', 0));
                } else {
                    $response->assertForbidden();
                }
                $responses[] = $response;
            }
        }

        return $responses;
    }),
    'reservations.cancel' => RouteCoverage::attempt(function (TwoTenants $t) {
        $reservation = inTenant($t->b->property, fn () => app(CreateReservation::class)->handle($t->b->owner, BookingForm::details($t->b->owner)));
        $response = $this->actingAs($t->a->owner)->post(route('reservations.cancel', $reservation), ['_property' => $t->a->property->id, 'reason' => 'Wrong property'])->assertNotFound();
        inTenant($t->b->property, function () use ($reservation) {
            expect($reservation->fresh()->status)->toBe(ReservationStatus::Confirmed)
                ->and($reservation->lifecycleEvents()->count())->toBe(1)
                ->and((int) InventoryRecord::query()->sum('sold_count'))->toBe(2);
        });

        return $response;
    }),
    'reservations.void' => RouteCoverage::attempt(function (TwoTenants $t) {
        $reservation = inTenant($t->b->property, fn () => app(CreateReservation::class)->handle($t->b->owner, BookingForm::details($t->b->owner)));
        $response = $this->actingAs($t->a->owner)->post(route('reservations.void', $reservation), ['_property' => $t->a->property->id, 'reason' => 'Wrong property'])->assertNotFound();
        inTenant($t->b->property, function () use ($reservation) {
            expect($reservation->fresh()->status)->toBe(ReservationStatus::Confirmed)
                ->and($reservation->lifecycleEvents()->count())->toBe(1)
                ->and((int) InventoryRecord::query()->sum('sold_count'))->toBe(2);
        });

        return $response;
    }),
    'reservations.create' => RouteCoverage::attempt(function (TwoTenants $t) {
        $details = inTenant($t->b->property, fn () => BookingForm::details($t->b->owner));

        return $this->actingAs($t->a->owner)->getJson(route('reservations.create', $details))->assertUnprocessable()->assertJsonValidationErrors(['room_type_id', 'rate_plan_id', 'booker_id']);
    }),
    'reservations.store' => RouteCoverage::attempt(function (TwoTenants $t) {
        $details = inTenant($t->b->property, fn () => BookingForm::details($t->b->owner));
        $response = $this->actingAs($t->a->owner)->postJson(route('reservations.store'), [...$details, '_property' => $t->a->property->id])->assertUnprocessable()->assertJsonValidationErrors(['room_type_id', 'rate_plan_id', 'booker_id']);
        expect(inTenant($t->b->property, fn () => Reservation::query()->count()))->toBe(0);
        expect(inTenant($t->b->property, fn () => InventoryRecord::query()->sum('sold_count')))->toBe(0);

        return $response;
    }),
    'reservations.show' => RouteCoverage::attempt(function (TwoTenants $t) {
        $reservation = inTenant($t->b->property, fn () => app(CreateReservation::class)->handle($t->b->owner, BookingForm::details($t->b->owner)));
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->get(route('reservations.show', $reservation->reservation_number))->assertNotFound();
        }

        return $responses;
    }),
    'reservations.availability' => RouteCoverage::attempt(function (TwoTenants $t) {
        $type = inTenant($t->b->property, fn () => app(CreateRoomType::class)->handle($t->b->owner, InventoryForm::roomType(['name' => 'Bravo Secret Suite', 'code' => 'BRVX'])));
        $stay = ['arrival' => '2026-12-10', 'departure' => '2026-12-12', 'adults' => 2, 'children' => 0];
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->get(route('reservations.availability', $stay))->assertDontSee('Bravo Secret Suite')->assertDontSee($type->id);
        }
        $responses[] = $this->actingAs($t->a->owner)->getJson(route('reservations.availability', [...$stay, 'room_type_id' => $type->id]))->assertUnprocessable()->assertJsonValidationErrors('room_type_id');

        return $responses;
    }),
];
