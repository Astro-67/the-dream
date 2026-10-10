<?php

use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Reservations\Actions\CancelReservation;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Actions\VoidReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

test('termination refuses every non-confirmed state and stale repeated requests', function (string $action, ReservationStatus $status) {
    [$owner, , $property] = organizationWithOwner();
    $reservation = inTenant($property, function () use ($owner, $status) {
        $reservation = app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
        $reservation->update(['status' => $status]);

        return $reservation;
    });
    $this->actingAs($owner)->postJson(route('reservations.'.$action, $reservation), ['_property' => $property->id, 'reason' => 'Invalid transition'])->assertUnprocessable()->assertJsonValidationErrors('reservation');
    inTenant($property, function () use ($reservation, $status) {
        expect($reservation->fresh()->status)->toBe($status)->and($reservation->lifecycleEvents()->count())->toBe(1)
            ->and(InventoryRecord::query()->sum('sold_count'))->toBe(2);
    });
})->with(['cancel', 'void'])->with(array_filter(ReservationStatus::cases(), fn ($status) => $status !== ReservationStatus::Confirmed));

test('void requires a reason and both routes require the opening property intent', function (string $action) {
    $t = twoTenants();
    $reservation = inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    $this->actingAs($t->a->owner);
    $this->postJson(route('reservations.'.$action, $reservation), ['_property' => $t->a->property->id])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson(route('reservations.'.$action, $reservation), ['_property' => $t->a->property->id, 'reason' => str_repeat('x', 2001)])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson(route('reservations.'.$action, $reservation), ['reason' => 'Changed plans'])->assertUnprocessable()->assertJsonValidationErrors('_property');
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->otherProperty->id])
        ->postJson(route('reservations.'.$action, $reservation), ['_property' => $t->a->otherProperty->id, 'reason' => 'Wrong property'])->assertNotFound();
    inTenant($t->a->property, fn () => expect($reservation->fresh()->status)->toBe(ReservationStatus::Confirmed));
})->with(['cancel', 'void']);

test('cancellation role access is enforced on the write route', function (OrganizationRole $role, bool $allowed) {
    $t = twoTenants();
    $reservation = inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    $this->actingAs($t->a->member($role))->post(route('reservations.cancel', $reservation), ['_property' => $t->a->property->id, 'reason' => 'Changed plans'])->assertStatus($allowed ? 302 : 403);
    inTenant($t->a->property, fn () => expect($reservation->fresh()->status)->toBe($allowed ? ReservationStatus::Cancelled : ReservationStatus::Confirmed));
})->with(array_map(fn ($role) => [$role, in_array($role, [OrganizationRole::Owner, OrganizationRole::GroupOperationsManager, OrganizationRole::PropertyManager, OrganizationRole::FrontDesk])], OrganizationRole::cases()));

test('termination and its lifecycle event roll back with their enclosing transaction', function (string $action) {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner, $action) {
        $details = BookingForm::details($owner);
        $reservation = app(CreateReservation::class)->handle($owner, $details);
        $class = $action === 'cancel' ? CancelReservation::class : VoidReservation::class;
        expect(fn () => DB::transaction(function () use ($owner, $reservation, $class) {
            app($class)->handle($owner, $reservation, 'Changed plans');
            throw new RuntimeException('Abort termination');
        }))->toThrow(RuntimeException::class, 'Abort termination');
        expect($reservation->fresh()->status)->toBe(ReservationStatus::Confirmed)->and($reservation->lifecycleEvents()->count())->toBe(1);
        expect(fn () => app(CreateReservation::class)->handle($owner, $details))->toThrow(ValidationException::class);
        app($class)->handle($owner, $reservation, 'Changed plans');
        expect(fn () => app($class)->handle($owner, $reservation, 'Repeated stale request'))->toThrow(ValidationException::class);
        app(CreateReservation::class)->handle($owner, $details);
        expect(fn () => app(CreateReservation::class)->handle($owner, $details))->toThrow(ValidationException::class);
    });
})->with(['cancel', 'void']);
