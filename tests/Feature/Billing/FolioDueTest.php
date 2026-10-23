<?php

use App\Domains\Billing\Actions\OpenFolio;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\PostRoomCharge;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\Folio;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;

/** A confirmed USD stay of two nights at USD 100 with no tax rules, on its arrival day. */
function dueStay(): array
{
    $t = twoTenants();
    $t->a->property->update(['business_date' => '2026-12-10']);
    $reservation = inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, [...BookingForm::details($t->a->owner), 'adults' => 1, 'children' => 0]));

    return [$t, $reservation];
}

function duePay(object $t, int $amount): void
{
    inTenant($t->a->property, fn () => app(RecordPayment::class)->handle($t->a->owner, Folio::query()->sole(), ['method' => 'bank_transfer', 'amount' => $amount, 'currency' => 'USD']));
}

test('an unpaid confirmed stay is due its unposted nights', function () {
    [$t, $reservation] = dueStay();

    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page
        ->where('totals.0.estimate', ['nights' => 2, 'amount' => 20000])->where('totals.0.due', 20000)->where('totals.0.status', 'unpaid'));
});

test('a payment before check-in leaves the stay partially paid and due the rest', function () {
    [$t, $reservation] = dueStay();
    duePay($t, 5000);

    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page
        ->where('totals.0.due', 15000)->where('totals.0.status', 'partially_paid'));
});

test('paying the whole stay leaves nothing due, and overpaying leaves a credit', function (int $amount, int $due) {
    [$t, $reservation] = dueStay();
    duePay($t, $amount);

    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page
        ->where('totals.0.due', $due)->where('totals.0.status', 'paid'));
})->with([[20000, 0], [25000, -5000]]);

test('posting a night moves it from the estimate to the balance without changing what is due', function () {
    [$t, $reservation] = dueStay();
    duePay($t, 5000);
    inTenant($t->a->property, function () use ($reservation) {
        $reservation->update(['status' => ReservationStatus::CheckedIn]);
        app(PostRoomCharge::class)->handle(null, $reservation, '2026-12-10');
    });

    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page
        ->where('totals.0.estimate', ['nights' => 1, 'amount' => 10000])->where('totals.0.balance', 5000)
        ->where('totals.0.due', 15000)->where('totals.0.status', 'partially_paid'));
});

test('each currency has its own due and status, never added together', function () {
    [$t, $reservation] = dueStay();
    duePay($t, 20000);
    inTenant($t->a->property, function () use ($t, $reservation) {
        $folio = app(OpenFolio::class)->handle($t->a->owner, $reservation, 'TZS');
        app(PostExtraCharge::class)->handle($t->a->owner, $folio, ['category' => 'laundry', 'quantity' => 1, 'unit_price' => 30000]);
    });

    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page
        ->where('totals.0.currency', 'TZS')->where('totals.0.due', 30000)->where('totals.0.status', 'unpaid')
        ->where('totals.1.currency', 'USD')->where('totals.1.due', 0)->where('totals.1.status', 'paid'));
});

test('the folio header names the guest, reservation, room, room type and nights', function () {
    [$t, $reservation] = dueStay();

    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page
        ->where('stay.guest', 'Travel Booker')->where('stay.reservation_number', $reservation->reservation_number)
        ->where('stay.room', null)->where('stay.room_type', 'Deluxe Double')->where('stay.nights', 2));
});
