<?php

use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

return [
    'reservations.amend-preview' => RouteCoverage::attempt(function (TwoTenants $t) {
        [$reservation, $details] = inTenant($t->b->property, function () use ($t) {
            $details = BookingForm::details($t->b->owner);

            return [app(CreateReservation::class)->handle($t->b->owner, $details), $details];
        });

        return $this->actingAs($t->a->owner)->get(route('reservations.amend-preview', $reservation))->assertNotFound();
    }),
    'reservations.amend' => RouteCoverage::attempt(function (TwoTenants $t) {
        [$reservation, $details] = inTenant($t->b->property, function () use ($t) {
            $details = BookingForm::details($t->b->owner);

            return [app(CreateReservation::class)->handle($t->b->owner, $details), $details];
        });
        $response = $this->actingAs($t->a->owner)->patch(route('reservations.amend', $reservation), [...$details, 'room_id' => null, 'pricing' => 'keep', '_property' => $t->a->property->id])->assertNotFound();
        inTenant($t->b->property, function () use ($reservation) {
            expect($reservation->fresh()->arrival->toDateString())->toBe('2026-12-10')
                ->and($reservation->lifecycleEvents()->count())->toBe(1)
                ->and((int) InventoryRecord::query()->sum('sold_count'))->toBe(2);
        });

        return $response;
    }),
    'reservations.paying-institution' => RouteCoverage::attempt(function (TwoTenants $t) {
        $institution = ['paying_institution_type' => 'business', 'paying_institution_name' => 'Bravo Supplies', 'paying_institution_zrb_number' => 'Z-BRAVO'];
        $book = fn ($property, $owner) => inTenant($property, fn () => app(CreateReservation::class)->handle($owner, [...BookingForm::details($owner), ...$institution]));
        $foreign = $book($t->b->property, $t->b->owner);
        $sibling = $book($t->a->otherProperty, $t->a->owner);
        $form = ['paying_institution_type' => 'government', 'paying_institution_name' => 'Attacker', 'paying_institution_zrb_number' => 'Z-ATTACK'];
        $frontDesk = $t->a->member(OrganizationRole::FrontDesk);
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->patch(route('reservations.paying-institution', $foreign), [...$form, '_property' => $t->a->property->id])->assertNotFound();
        }
        $responses[] = $this->actingAs($t->a->owner)->patch(route('reservations.paying-institution', $foreign), [...$form, '_property' => $t->b->property->id])->assertForbidden();
        // Sibling property: looked up in the opening property, so not found; a form for a property the member cannot act in is refused.
        $responses[] = $this->actingAs($frontDesk)->patch(route('reservations.paying-institution', $sibling), [...$form, '_property' => $t->a->property->id])->assertNotFound();
        $responses[] = $this->actingAs($frontDesk)->patch(route('reservations.paying-institution', $sibling), [...$form, '_property' => $t->a->otherProperty->id])->assertForbidden();
        foreach ([[$t->b->property, $foreign], [$t->a->otherProperty, $sibling]] as [$property, $reservation]) {
            inTenant($property, fn () => expect($reservation->fresh()->paying_institution_zrb_number)->toBe('Z-BRAVO')
                ->and($reservation->lifecycleEvents()->where('type', 'paying_institution_changed')->count())->toBe(0));
        }

        return $responses;
    }),
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
    'reservations.statement' => RouteCoverage::attempt(function (TwoTenants $t) {
        $reservation = inTenant($t->b->property, fn () => app(CreateReservation::class)->handle($t->b->owner, BookingForm::details($t->b->owner)));
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->get(route('reservations.statement', $reservation->reservation_number))->assertNotFound();
        }

        return $responses;
    }),
    'reservations.group-statement' => RouteCoverage::attempt(function (TwoTenants $t) {
        $reservation = inTenant($t->b->property, fn () => app(CreateReservation::class)->handle($t->b->owner, BookingForm::details($t->b->owner)));
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->get(route('reservations.group-statement', $reservation))->assertNotFound();
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
