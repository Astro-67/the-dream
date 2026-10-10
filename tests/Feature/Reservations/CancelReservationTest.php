<?php

use App\Domains\Reservations\Actions\CancelReservation;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia;
use Tests\Support\BookingForm;

test('cancelling releases every night for resale without reusing the reservation number', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        $reservation = app(CreateReservation::class)->handle($owner, $details);
        $cancelled = app(CancelReservation::class)->handle($owner, $reservation, 'Guest changed plans');
        expect($cancelled->status)->toBe(ReservationStatus::Cancelled);
        $event = $cancelled->lifecycleEvents()->where('type', 'cancelled')->sole();
        expect($event->sequence)->toBe(2)->and($event->actor_id)->toBe($owner->id)
            ->and($event->snapshot['reason'])->toBe('Guest changed plans')
            ->and($event->snapshot['status'])->toBe('cancelled');
        $replacement = app(CreateReservation::class)->handle($owner, $details);
        expect($replacement->reservation_number)->not->toBe($cancelled->reservation_number);
    });
});

test('front desk cancels through the property intent route with a required reason', function () {
    $t = twoTenants();
    $reservation = inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));
    $this->postJson(route('reservations.cancel', $reservation), ['_property' => $t->a->property->id, 'reason' => '   '])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->post(route('reservations.cancel', $reservation), ['_property' => $t->a->property->id, 'reason' => 'Guest changed plans'])->assertRedirect(route('reservations.show', $reservation));
    $this->get(route('reservations.show', $reservation))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('reservation.status', 'cancelled'));
});

test('only managers can void a confirmed reservation created in error', function (OrganizationRole $role, bool $allowed) {
    $t = twoTenants();
    $details = inTenant($t->a->property, fn () => BookingForm::details($t->a->owner));
    $reservation = inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, $details));
    $actor = $t->a->member($role);
    $this->actingAs($actor)->post(route('reservations.void', $reservation), ['_property' => $t->a->property->id, 'reason' => 'Duplicate entry'])->assertStatus($allowed ? 302 : 403);
    inTenant($t->a->property, function () use ($allowed, $reservation, $actor, $details, $t) {
        expect($reservation->fresh()->status)->toBe($allowed ? ReservationStatus::Void : ReservationStatus::Confirmed);
        if ($allowed) {
            $event = $reservation->lifecycleEvents()->where('type', 'voided')->sole();
            expect($event->snapshot['reason'])->toBe('Duplicate entry')->and($event->actor_id)->toBe($actor->id)->and($event->sequence)->toBe(2);
            expect(app(CreateReservation::class)->handle($t->a->owner, $details)->reservation_number)->not->toBe($reservation->reservation_number);
        }
    });
})->with(array_map(fn ($role) => [$role, in_array($role, [OrganizationRole::Owner, OrganizationRole::GroupOperationsManager, OrganizationRole::PropertyManager])], OrganizationRole::cases()));

test('reservation detail offers only permitted confirmed termination actions', function (OrganizationRole $role, bool $cancel, bool $void) {
    $t = twoTenants();
    $reservation = inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    $this->actingAs($t->a->member($role))->get(route('reservations.show', $reservation))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('can.cancel', $cancel)->where('can.void', $void));
})->with([[OrganizationRole::FrontDesk, true, false], [OrganizationRole::PropertyManager, true, true], [OrganizationRole::Finance, false, false]]);
