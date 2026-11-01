<?php

use App\Domains\Billing\Actions\OpenFolio;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\Folio;
use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\FrontDesk\Actions\CheckOut;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Enums\TourOperatorPaysFor;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;
use Tests\Support\TenantFixture;

/** A guest checked in on 10 December, departing 12 December; the business date is the departure date. */
function departingGuestForCheckOut(TenantFixture $tenant): Reservation
{
    $tenant->property->update(['business_date' => '2026-12-10']);
    $reservation = inTenant($tenant->property, function () use ($tenant) {
        $reservation = app(CreateReservation::class)->handle($tenant->owner, [...BookingForm::details($tenant->owner), 'adults' => 1, 'children' => 0, 'room_id' => Room::query()->sole()->id]);
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        $guest = app(CreateGuest::class)->handle($tenant->owner, ['name' => 'Leaving Guest', 'documents' => [['type' => 'passport', 'number' => 'P7654321', 'nationality' => 'DE']]]);
        ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);

        return app(CheckIn::class)->handle($tenant->owner, $reservation);
    });
    $tenant->property->update(['business_date' => '2026-12-12']);

    return $reservation;
}

/** Posts an extra charge of $amount minor units to the reservation's folio. */
function chargeBeforeCheckOut(TenantFixture $tenant, Reservation $reservation, int $amount): Folio
{
    return inTenant($tenant->property, function () use ($tenant, $reservation, $amount) {
        $folio = Folio::query()->where('reservation_id', $reservation->id)->sole();
        app(PostExtraCharge::class)->handle($tenant->owner, $folio, ['category' => 'minibar', 'quantity' => 1, 'unit_price' => $amount]);

        return $folio;
    });
}

test('front desk checks out a settled stay, marking the room dirty and recording one event', function () {
    $t = twoTenants();
    $reservation = departingGuestForCheckOut($t->a);
    $folio = chargeBeforeCheckOut($t->a, $reservation, 1500);
    inTenant($t->a->property, fn () => app(RecordPayment::class)->handle($t->a->owner, $folio, ['method' => 'bank_transfer', 'amount' => 1500, 'currency' => 'USD']));
    $actor = $t->a->member(OrganizationRole::FrontDesk);

    $this->actingAs($actor)->post(route('reservations.check-out', $reservation), ['_property' => $t->a->property->id])
        ->assertRedirect(route('reservations.show', $reservation))->assertSessionHasNoErrors();

    inTenant($t->a->property, function () use ($reservation, $actor) {
        expect($reservation->fresh()->status)->toBe(ReservationStatus::CheckedOut)
            ->and(RoomStatus::query()->where('room_id', $reservation->room_id)->sole()->status)->toBe(HousekeepingStatus::Dirty);
        $event = $reservation->lifecycleEvents()->where('type', 'checked_out')->sole();
        expect($event->actor_id)->toBe($actor->id)->and($event->snapshot['status'])->toBe('checked_out');
    });
});

test('check-out is refused while any folio balance is not zero', function () {
    $t = twoTenants();
    $reservation = departingGuestForCheckOut($t->a);
    chargeBeforeCheckOut($t->a, $reservation, 1500);

    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->postJson(route('reservations.check-out', $reservation), ['_property' => $t->a->property->id])
        ->assertUnprocessable()->assertJsonValidationErrors('balance');

    inTenant($t->a->property, function () use ($reservation) {
        expect($reservation->fresh()->status)->toBe(ReservationStatus::CheckedIn)
            ->and(RoomStatus::query()->where('room_id', $reservation->room_id)->sole()->status)->toBe(HousekeepingStatus::Occupied)
            ->and($reservation->lifecycleEvents()->where('type', 'checked_out')->exists())->toBeFalse();
    });
});

test('credit balances follow the existing property resolution policy', function (bool $blockCredit) {
    $t = twoTenants();
    $reservation = departingGuestForCheckOut($t->a);
    $t->a->property->update(['payment_settings' => ['block_checkout_if_unpaid' => $blockCredit]]);
    inTenant($t->a->property, fn () => app(RecordPayment::class)->handle($t->a->owner, Folio::query()->where('reservation_id', $reservation->id)->sole(), ['method' => 'bank_transfer', 'amount' => 500, 'currency' => 'USD']));

    $response = $this->actingAs($t->a->member(OrganizationRole::FrontDesk))
        ->postJson(route('reservations.check-out', $reservation), ['_property' => $t->a->property->id]);
    $blockCredit ? $response->assertUnprocessable()->assertJsonValidationErrors('balance') : $response->assertRedirect();
    expect(inTenant($t->a->property, fn () => $reservation->fresh()->status))
        ->toBe($blockCredit ? ReservationStatus::CheckedIn : ReservationStatus::CheckedOut);
})->with([true, false]);

test('a tour operator promise never clears posted debt', function () {
    $t = twoTenants();
    $reservation = departingGuestForCheckOut($t->a);
    inTenant($t->a->property, fn () => $reservation->update([
        'booked_through' => 'tour_operator', 'tour_operator_name' => 'Test operator',
        'tour_operator_registration' => 'TEST-REG', 'tour_operator_pays_for' => TourOperatorPaysFor::RoomLevyAndOtherServices,
    ]));
    chargeBeforeCheckOut($t->a, $reservation, 1500);
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->postJson(route('reservations.check-out', $reservation), ['_property' => $t->a->property->id])
        ->assertUnprocessable()->assertJsonPath('errors.balance.0', 'Check-out is not allowed. All outstanding balances must be paid before check-out.');
    expect(inTenant($t->a->property, fn () => $reservation->fresh()->status))->toBe(ReservationStatus::CheckedIn);
});

test('channel collection settles debt only after the payment is actually posted', function () {
    $t = twoTenants();
    $reservation = departingGuestForCheckOut($t->a);
    $folio = chargeBeforeCheckOut($t->a, $reservation, 1500);
    inTenant($t->a->property, fn () => $reservation->update(['payment_collector' => 'channel', 'channel_collected_amount' => 1500]));
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));
    $this->postJson(route('reservations.check-out', $reservation), ['_property' => $t->a->property->id])
        ->assertUnprocessable()->assertJsonValidationErrors('balance');

    inTenant($t->a->property, fn () => app(RecordPayment::class)->handle($t->a->owner, $folio, ['method' => 'channel_collected', 'currency' => 'USD']));
    $this->post(route('reservations.check-out', $reservation), ['_property' => $t->a->property->id])->assertSessionHasNoErrors();
    expect(inTenant($t->a->property, fn () => $reservation->fresh()->status))->toBe(ReservationStatus::CheckedOut);
});

test('check-out reads every folio without offsetting debt against another folio credit', function (string $currency) {
    $t = twoTenants();
    $reservation = departingGuestForCheckOut($t->a);
    $due = chargeBeforeCheckOut($t->a, $reservation, 1500);
    $credit = inTenant($t->a->property, function () use ($t, $reservation, $currency) {
        $folio = app(OpenFolio::class)->handle($t->a->owner, $reservation, $currency);
        app(RecordPayment::class)->handle($t->a->owner, $folio, ['method' => 'bank_transfer', 'amount' => 1500, 'currency' => $currency]);

        return $folio;
    });
    $expected = [
        ['id' => $due->id, 'currency' => 'USD', 'balance' => 1500],
        ['id' => $credit->id, 'currency' => $currency, 'balance' => -1500],
    ];
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))
        ->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id]);

    $this->get(route('reservations.show', $reservation))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('checkoutBalances', $expected));
    $this->get(route('reservations.index', ['list' => 'departures']))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('reservations.data.0.checkout_balances', $expected));
    $this->postJson(route('reservations.check-out', $reservation), ['_property' => $t->a->property->id])
        ->assertUnprocessable()->assertJsonPath('errors.balance.0', 'Check-out is not allowed. All outstanding balances must be paid before check-out.');
    inTenant($t->a->property, function () use ($reservation, $due, $credit) {
        expect($reservation->fresh()->status)->toBe(ReservationStatus::CheckedIn)
            ->and($due->balance()->amount)->toBe(1500)
            ->and($credit->balance()->amount)->toBe(-1500);
    });
})->with(['USD', 'EUR']);

test('only managers and owners may override an open balance, with a reason, and the override is audited', function (OrganizationRole $role, bool $allowed) {
    $t = twoTenants();
    $reservation = departingGuestForCheckOut($t->a);
    chargeBeforeCheckOut($t->a, $reservation, 1500);
    $actor = $role === OrganizationRole::Owner ? $t->a->owner : $t->a->member($role);

    $response = $this->actingAs($actor)->post(route('reservations.check-out', $reservation), ['_property' => $t->a->property->id, 'override_reason' => 'Company pays by invoice']);

    $allowed ? $response->assertRedirect(route('reservations.show', $reservation))->assertSessionHasNoErrors() : $response->assertForbidden();
    inTenant($t->a->property, function () use ($reservation, $allowed) {
        expect($reservation->fresh()->status)->toBe($allowed ? ReservationStatus::CheckedOut : ReservationStatus::CheckedIn);
        if ($allowed) {
            $event = $reservation->lifecycleEvents()->where('type', 'checked_out')->sole();
            expect($event->snapshot['override_reason'])->toBe('Company pays by invoice')
                ->and($event->snapshot['open_balances'])->toBe([['amount' => 1500, 'currency' => 'USD']]);
            $audit = Activity::query()->where('event', 'checked_out')->sole();
            expect($audit->properties['override_reason'])->toBe('Company pays by invoice');
        }
    });
})->with(array_map(fn (OrganizationRole $role) => [$role, in_array($role, [OrganizationRole::Owner, OrganizationRole::PropertyManager], true)], OrganizationRole::cases()));

test('an override needs a reason', function () {
    $t = twoTenants();
    $reservation = departingGuestForCheckOut($t->a);
    chargeBeforeCheckOut($t->a, $reservation, 1500);

    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->postJson(route('reservations.check-out', $reservation), ['_property' => $t->a->property->id, 'override_reason' => '   '])
        ->assertUnprocessable()->assertJsonValidationErrors('balance');
});

test('turning off the legacy unpaid block cannot let ordinary staff check out debt', function () {
    $t = twoTenants();
    $reservation = departingGuestForCheckOut($t->a);
    $folio = chargeBeforeCheckOut($t->a, $reservation, 1500);
    $t->a->property->update(['payment_settings' => ['block_checkout_if_unpaid' => false, 'manager_override' => false]]);
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))
        ->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id]);

    $this->get(route('reservations.show', $reservation))->assertInertia(fn (AssertableInertia $page) => $page->where('blockCheckoutIfUnpaid', false));
    $this->get(route('reservations.index', ['list' => 'departures']))->assertInertia(fn (AssertableInertia $page) => $page->where('blockCheckoutIfUnpaid', false));
    $this->post(route('reservations.check-out', $reservation), ['_property' => $t->a->property->id])
        ->assertSessionHasErrors(['balance' => 'Check-out is not allowed. All outstanding balances must be paid before check-out.']);

    inTenant($t->a->property, function () use ($reservation, $folio) {
        expect($reservation->fresh()->status)->toBe(ReservationStatus::CheckedIn)
            ->and($folio->balance()->amount)->toBe(1500)
            ->and(RoomStatus::query()->where('room_id', $reservation->room_id)->sole()->status)->toBe(HousekeepingStatus::Occupied)
            ->and($reservation->lifecycleEvents()->where('type', 'checked_out')->exists())->toBeFalse();
    });
});

test('a property may disable manager overrides on unpaid check-out', function () {
    $t = twoTenants();
    $reservation = departingGuestForCheckOut($t->a);
    chargeBeforeCheckOut($t->a, $reservation, 1500);
    $t->a->property->update(['payment_settings' => ['manager_override' => false]]);
    $this->actingAs($t->a->owner)
        ->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id]);

    $this->get(route('reservations.show', $reservation))->assertInertia(fn (AssertableInertia $page) => $page->where('can.overrideBalance', false));
    $this->get(route('reservations.index', ['list' => 'departures']))->assertInertia(fn (AssertableInertia $page) => $page->where('reservations.data.0.can_override_balance', false));
    $this->postJson(route('reservations.check-out', $reservation), ['_property' => $t->a->property->id, 'override_reason' => 'Invoice'])
        ->assertForbidden();
    $this->postJson(route('reservations.check-out', $reservation), ['_property' => $t->a->property->id])
        ->assertUnprocessable()->assertJsonValidationErrors('balance');
    inTenant($t->a->property, function () use ($t, $reservation) {
        expect(fn () => app(CheckOut::class)->handle($t->a->owner, $reservation, 'Invoice'))
            ->toThrow(ValidationException::class);
    });
    expect(inTenant($t->a->property, fn () => $reservation->fresh()->status))->toBe(ReservationStatus::CheckedIn);
});

test('a stay departing later needs an early departure before check-out', function () {
    $t = twoTenants();
    $reservation = departingGuestForCheckOut($t->a);
    $t->a->property->update(['business_date' => '2026-12-11']);

    $this->actingAs($t->a->owner)->postJson(route('reservations.check-out', $reservation), ['_property' => $t->a->property->id])
        ->assertUnprocessable()->assertJsonValidationErrors('reservation');
});

test('today\'s departures list offers check-out to those who may check out', function (OrganizationRole $role, bool $offered) {
    $t = twoTenants();
    $reservation = departingGuestForCheckOut($t->a);

    chargeBeforeCheckOut($t->a, $reservation, 1500);

    $this->actingAs($t->a->member($role))->get(route('reservations.index', ['list' => 'departures']))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('reservations.data.0.reservation_number', $reservation->reservation_number)->where('reservations.data.0.can_check_out', $offered)
            ->when($offered, fn (AssertableInertia $page) => $page->where('reservations.data.0.balances', [['currency' => 'USD', 'balance' => 1500, 'unposted' => true]])
                ->where('reservations.data.0.can_override_balance', $role === OrganizationRole::PropertyManager)));
})->with([[OrganizationRole::FrontDesk, true], [OrganizationRole::PropertyManager, true], [OrganizationRole::Finance, false], [OrganizationRole::Auditor, false]]);

test('the reservation page offers check-out and, to managers, the balance override', function (OrganizationRole $role, bool $checkOut, bool $override) {
    $t = twoTenants();
    $reservation = departingGuestForCheckOut($t->a);

    $this->actingAs($t->a->member($role))->get(route('reservations.show', $reservation))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.checkOut', $checkOut)->where('can.overrideBalance', $override));
})->with([[OrganizationRole::FrontDesk, true, false], [OrganizationRole::PropertyManager, true, true], [OrganizationRole::Finance, false, false]]);

test('check-out is not offered before the departure date or once checked out', function () {
    $t = twoTenants();
    $reservation = departingGuestForCheckOut($t->a);
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner);
    $t->a->property->update(['business_date' => '2026-12-11']);
    $this->get(route('reservations.index', ['list' => 'in-house']))->assertInertia(fn (AssertableInertia $page) => $page->where('reservations.data.0.can_check_out', false));
    $t->a->property->update(['business_date' => '2026-12-12']);
    $this->post(route('reservations.check-out', $reservation), ['_property' => $t->a->property->id])->assertSessionHasNoErrors();
    $this->get(route('reservations.index', ['list' => 'departures']))->assertInertia(fn (AssertableInertia $page) => $page->where('reservations.data.0.status', 'checked_out')->where('reservations.data.0.can_check_out', false));
});

test('only an in-house reservation can be checked out', function () {
    $t = twoTenants();
    $reservation = departingGuestForCheckOut($t->a);
    inTenant($t->a->property, fn () => $reservation->update(['status' => ReservationStatus::Confirmed]));

    $this->actingAs($t->a->owner)->postJson(route('reservations.check-out', $reservation), ['_property' => $t->a->property->id])
        ->assertUnprocessable()->assertJsonValidationErrors('reservation');
});

test('finance sees open balances after departure until they are settled', function () {
    $t = twoTenants();
    $reservation = departingGuestForCheckOut($t->a);
    $folio = chargeBeforeCheckOut($t->a, $reservation, 1500);
    inTenant($t->a->property, fn () => app(CheckOut::class)->handle($t->a->owner, $reservation, 'Company pays by invoice'));
    $this->actingAs($t->a->member(OrganizationRole::Finance));

    $this->get(route('open-balances.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('billing/open-balances')
        ->has('folios.data', 1)
        ->where('folios.data.0.reservation_number', $reservation->reservation_number)
        ->where('folios.data.0.currency', 'USD')
        ->where('folios.data.0.balance', 1500));

    inTenant($t->a->property, fn () => app(RecordPayment::class)->handle($t->a->owner, $folio, ['method' => 'bank_transfer', 'amount' => 1500, 'currency' => 'USD']));
    $this->get(route('open-balances.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('folios.data', 0));
});

test('in-house balances are not open balances after departure', function () {
    $t = twoTenants();
    $reservation = departingGuestForCheckOut($t->a);
    chargeBeforeCheckOut($t->a, $reservation, 1500);

    $this->actingAs($t->a->owner)->get(route('open-balances.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('folios.data', 0));
});

test('only roles that view finance see open balances', function (OrganizationRole $role, bool $allowed) {
    $t = twoTenants();
    $actor = $role === OrganizationRole::Owner ? $t->a->owner : $t->a->member($role);

    $this->actingAs($actor)->get(route('open-balances.index'))->assertStatus($allowed ? 200 : 403);
})->with(array_map(fn (OrganizationRole $role) => [$role, in_array($role, [OrganizationRole::Owner, OrganizationRole::GroupOperationsManager, OrganizationRole::Finance, OrganizationRole::Auditor], true)], OrganizationRole::cases()));
