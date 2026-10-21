<?php

use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Models\Folio;
use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\FrontDesk\Mail\AutomaticCheckoutSummary;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Support\BookingForm;
use Tests\Support\TenantFixture;

function automaticCheckoutStay(TenantFixture $tenant): Reservation
{
    $tenant->property->update(['business_date' => '2026-12-10']);
    $reservation = inTenant($tenant->property, function () use ($tenant) {
        $reservation = app(CreateReservation::class)->handle($tenant->owner, [...BookingForm::details($tenant->owner), 'room_id' => Room::query()->sole()->id]);
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        $guest = app(CreateGuest::class)->handle($tenant->owner, ['name' => 'Automatic Departure', 'documents' => [['type' => 'passport', 'number' => 'P1260001', 'nationality' => 'DE']]]);
        ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);

        return app(CheckIn::class)->handle($tenant->owner, $reservation);
    });
    $tenant->property->update(['business_date' => '2026-12-12']);

    return $reservation;
}

test('automatic check-out waits for night audit before completing the local day', function () {
    Mail::fake();
    $t = twoTenants();
    $stay = automaticCheckoutStay($t->a);
    $t->a->property->update(['business_date' => '2026-12-11', 'front_desk_settings' => ['automatic_checkout_enabled' => true]]);
    $this->travelTo(new DateTimeImmutable('2026-12-12 09:00:00 UTC'));
    $this->artisan('front-desk:checkout-due')->assertSuccessful();
    expect($t->a->property->fresh()->automatic_checkout_date)->toBeNull();
    Mail::assertNothingOutgoing();
    $this->artisan('night-audit:close-due')->assertSuccessful();
    $this->artisan('front-desk:checkout-due')->assertSuccessful();
    $this->artisan('front-desk:checkout-due')->assertSuccessful();
    expect(inTenant($t->a->property, fn () => $stay->fresh()->status))->toBe(ReservationStatus::CheckedOut)
        ->and($t->a->property->fresh()->automatic_checkout_date->toDateString())->toBe('2026-12-12');
    Mail::assertQueued(AutomaticCheckoutSummary::class, 1);
});

test('automatic check-out runs once per local day after the configured time for each enabled property', function () {
    Mail::fake();
    $t = twoTenants();
    $east = automaticCheckoutStay($t->a);
    $west = automaticCheckoutStay($t->b);
    $t->a->property->update(['timezone' => 'Africa/Dar_es_Salaam', 'front_desk_settings' => ['automatic_checkout_enabled' => true]]);
    $t->b->property->update(['timezone' => 'America/New_York', 'front_desk_settings' => ['automatic_checkout_enabled' => true]]);
    $this->travelTo(new DateTimeImmutable('2026-12-12 08:58:00 UTC'));
    $this->artisan('front-desk:checkout-due')->assertSuccessful();
    Mail::assertNothingOutgoing();

    $this->travelTo(new DateTimeImmutable('2026-12-12 08:59:00 UTC'));
    $this->artisan('front-desk:checkout-due')->assertSuccessful();
    $this->artisan('front-desk:checkout-due')->assertSuccessful();
    expect(inTenant($t->a->property, fn () => $east->fresh()->status))->toBe(ReservationStatus::CheckedOut)
        ->and(inTenant($t->b->property, fn () => $west->fresh()->status))->toBe(ReservationStatus::CheckedIn);
    inTenant($t->a->property, function () use ($east) {
        expect($east->lifecycleEvents()->where('type', 'checked_out')->sole()->actor_id)->toBeNull()
            ->and(RoomStatus::query()->where('room_id', $east->room_id)->sole()->status)->toBe(HousekeepingStatus::Dirty);
    });
    Mail::assertQueued(AutomaticCheckoutSummary::class, 1);
    Mail::assertQueued(AutomaticCheckoutSummary::class, fn ($mail) => $mail->hasTo($t->a->property->email));

    $this->travelTo(new DateTimeImmutable('2026-12-12 16:59:00 UTC'));
    $this->artisan('front-desk:checkout-due')->assertSuccessful();
    expect(inTenant($t->b->property, fn () => $west->fresh()->status))->toBe(ReservationStatus::CheckedOut);
    Mail::assertQueued(AutomaticCheckoutSummary::class, 2);

    $this->travelTo(new DateTimeImmutable('2026-12-13 08:59:00 UTC'));
    $this->artisan('night-audit:close-due')->assertSuccessful();
    $this->artisan('front-desk:checkout-due')->assertSuccessful();
    Mail::assertQueued(AutomaticCheckoutSummary::class, 3);
});

test('automatic unpaid check-outs follow their own switch and preserve open balances after departure', function (bool $includeUnpaid, bool $blockManual) {
    Mail::fake();
    $t = twoTenants();
    $reservation = automaticCheckoutStay($t->a);
    $folio = inTenant($t->a->property, function () use ($t, $reservation) {
        $folio = Folio::query()->where('reservation_id', $reservation->id)->sole();
        app(PostExtraCharge::class)->handle($t->a->owner, $folio, ['item_name' => 'Laundry', 'quantity' => 1, 'unit_price' => 1500]);

        return $folio;
    });
    $t->a->property->update([
        'front_desk_settings' => ['automatic_checkout_enabled' => true, 'automatic_checkout_include_unpaid' => $includeUnpaid, 'automatic_checkout_summary_email' => 'summary@example.test'],
        'payment_settings' => ['block_checkout_if_unpaid' => $blockManual, 'manager_override' => false],
    ]);
    $this->travelTo(new DateTimeImmutable('2026-12-12 09:00:00 UTC'));
    $this->artisan('front-desk:checkout-due')->assertSuccessful();

    inTenant($t->a->property, function () use ($reservation, $folio, $includeUnpaid) {
        expect($reservation->fresh()->status)->toBe($includeUnpaid ? ReservationStatus::CheckedOut : ReservationStatus::CheckedIn)
            ->and($folio->fresh()->balance()->amount)->toBe(1500);
        if ($includeUnpaid) {
            $event = $reservation->lifecycleEvents()->where('type', 'checked_out')->sole();
            expect($event->snapshot['open_balances'])->toEqual([['amount' => 1500, 'currency' => 'USD']])
                ->and($event->actor_id)->toBeNull();
        }
    });
    Mail::assertQueued(AutomaticCheckoutSummary::class, function ($mail) use ($reservation, $includeUnpaid) {
        expect($mail->hasTo('summary@example.test'))->toBeTrue();
        if ($includeUnpaid) {
            expect($mail->checkedOut)->toBe([$reservation->reservation_number])->and($mail->skipped)->toBe([]);
        } else {
            expect($mail->checkedOut)->toBe([])
                ->and($mail->render())->toContain($reservation->reservation_number, 'Settle every folio balance before check-out.');
        }

        return true;
    });
    Mail::assertQueued(AutomaticCheckoutSummary::class, 1);
    $this->actingAs($t->a->member(OrganizationRole::Finance))->get(route('open-balances.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('billing/open-balances')
            ->has('folios.data', $includeUnpaid ? 1 : 0)
            ->when($includeUnpaid, fn (AssertableInertia $page) => $page
                ->where('folios.data.0.reservation_number', $reservation->reservation_number)
                ->where('folios.data.0.balance', 1500)));
})->with([[true, true], [true, false], [false, true], [false, false]]);

test('overstays are skipped with a summary reason unless explicitly included', function (bool $includeOverstays) {
    Mail::fake();
    $t = twoTenants();
    $reservation = automaticCheckoutStay($t->a);
    $t->a->property->update([
        'business_date' => '2026-12-13',
        'front_desk_settings' => ['automatic_checkout_enabled' => true, 'automatic_checkout_include_overstays' => $includeOverstays],
    ]);
    $this->travelTo(new DateTimeImmutable('2026-12-13 09:00:00 UTC'));
    $this->artisan('front-desk:checkout-due')->assertSuccessful();
    expect(inTenant($t->a->property, fn () => $reservation->fresh()->status))
        ->toBe($includeOverstays ? ReservationStatus::CheckedOut : ReservationStatus::CheckedIn);
    Mail::assertQueued(AutomaticCheckoutSummary::class, function ($mail) use ($includeOverstays, $reservation) {
        expect($mail->render())->toContain($reservation->reservation_number);
        if (! $includeOverstays) {
            expect($mail->render())->toContain('Overstays are excluded from automatic check-out.');
        }

        return true;
    });
})->with([false, true]);

test('disabled and archived properties and future departures are not checked out', function () {
    Mail::fake();
    $t = twoTenants();
    $disabled = automaticCheckoutStay($t->a);
    $archived = automaticCheckoutStay($t->b);
    $t->b->property->forceFill(['archived_at' => now(), 'front_desk_settings' => ['automatic_checkout_enabled' => true]])->save();
    $t->a->otherProperty->update(['business_date' => '2026-12-10', 'front_desk_settings' => ['automatic_checkout_enabled' => true]]);
    $future = inTenant($t->a->otherProperty, function () use ($t) {
        $reservation = app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
        $reservation->update(['status' => ReservationStatus::CheckedIn]);

        return $reservation;
    });
    $t->a->otherProperty->update(['business_date' => '2026-12-11']);
    $this->travelTo(new DateTimeImmutable('2026-12-12 09:00:00 UTC'));
    $this->artisan('front-desk:checkout-due')->assertSuccessful();
    expect(inTenant($t->a->property, fn () => $disabled->fresh()->status))->toBe(ReservationStatus::CheckedIn)
        ->and(inTenant($t->b->property, fn () => $archived->fresh()->status))->toBe(ReservationStatus::CheckedIn)
        ->and(inTenant($t->a->otherProperty, fn () => $future->fresh()->status))->toBe(ReservationStatus::CheckedIn);
    Mail::assertNothingOutgoing();
});

test('night audit never checks out stays even when automatic check-out is enabled and due', function () {
    Mail::fake();
    $t = twoTenants();
    $reservation = automaticCheckoutStay($t->a);
    $t->a->property->update(['front_desk_settings' => ['automatic_checkout_enabled' => true, 'automatic_checkout_include_overstays' => true]]);
    $this->travelTo(new DateTimeImmutable('2026-12-13 09:00:00 UTC'));
    $this->artisan('night-audit:close-due')->assertSuccessful();
    expect(inTenant($t->a->property, fn () => $reservation->fresh()->status))->toBe(ReservationStatus::CheckedIn);
    Mail::assertNothingOutgoing();
});

test('custom check-out times use the property calendar date across the international date line', function () {
    Mail::fake();
    $t = twoTenants();
    $reservation = automaticCheckoutStay($t->a);
    $t->a->property->update(['timezone' => 'Pacific/Auckland', 'front_desk_settings' => [
        'automatic_checkout_enabled' => true, 'automatic_checkout_time' => '12:15',
    ]]);
    inTenant($t->a->property, fn () => $reservation->update(['departure' => '2026-12-13']));
    $t->a->property->update(['business_date' => '2026-12-13']);
    $this->travelTo(new DateTimeImmutable('2026-12-12 23:14:00 UTC'));
    $this->artisan('front-desk:checkout-due')->assertSuccessful();
    Mail::assertNothingOutgoing();
    $this->travelTo(new DateTimeImmutable('2026-12-12 23:15:00 UTC'));
    $this->artisan('front-desk:checkout-due')->assertSuccessful();
    $this->travelTo(new DateTimeImmutable('2026-12-13 00:15:00 UTC'));
    $this->artisan('front-desk:checkout-due')->assertSuccessful();

    expect(inTenant($t->a->property, fn () => $reservation->fresh()->status))->toBe(ReservationStatus::CheckedOut);
    Mail::assertQueued(AutomaticCheckoutSummary::class, 1);
    Mail::assertQueued(AutomaticCheckoutSummary::class, fn ($mail) => $mail->date === '2026-12-13');
});
