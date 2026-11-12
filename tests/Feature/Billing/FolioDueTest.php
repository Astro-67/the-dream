<?php

use App\Domains\Billing\Actions\OpenFolio;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\PostRoomCharge;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\Folio;
use App\Domains\Rates\Actions\CreateTaxRule;
use App\Domains\Rates\Actions\SeedDefaultTaxRules;
use App\Domains\Rates\Actions\SetRatePrices;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Support\Money\ExchangeRate;
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

// protects=upfront payments include the same taxes night audit will charge; fails_when=the levy is left out of future nights or a posted night is counted twice.
test('an upfront payment covers three nights including the levy before and after night audit', function (string $currency, int $room, int $levy) {
    $t = twoTenants();
    $t->a->property->update(['business_date' => '2026-12-10', 'jurisdiction_profile' => 'zanzibar', 'star_rating' => 4]);
    ExchangeRate::query()->create(['date' => '2026-12-10', 'usd_tzs' => 2555, 'eur_tzs' => 2581.175]);
    $reservation = inTenant($t->a->property, function () use ($t, $currency, $room) {
        app(SeedDefaultTaxRules::class)->handle($t->a->owner, $t->a->property);
        $details = BookingForm::details($t->a->owner);
        $plan = RatePlan::query()->findOrFail($details['rate_plan_id']);
        $plan->update(['currency' => $currency]);
        app(SetRatePrices::class)->handle($t->a->owner, $plan, [
            'room_type_id' => $details['room_type_id'], 'from' => '2026-12-10', 'to' => '2026-12-12',
            'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'base_price' => $room, 'extra_adult' => 0, 'extra_child' => 0,
        ]);

        return app(CreateReservation::class)->handle($t->a->owner, [...$details, 'departure' => '2026-12-13', 'adults' => 2, 'children' => 0]);
    });
    $total = 3 * ($room + 2 * $levy);
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page
        ->where('totals.0.estimate', ['nights' => 3, 'amount' => $total])->where('totals.0.due', $total)->where('totals.0.charges', 0));

    $receipt = inTenant($t->a->property, function () use ($t, $currency, $total, $reservation) {
        $reservation->update(['status' => ReservationStatus::CheckedIn]);

        return app(RecordPayment::class)->handle($t->a->owner, Folio::query()->sole(), ['method' => 'bank_transfer', 'amount' => $total, 'currency' => $currency])->receipt;
    });
    $this->get(route('receipts.show', $receipt))->assertInertia(fn (Assert $page) => $page
        ->where('summary.estimate.amount', $total)->where('summary.charges', 0));

    foreach (['2026-12-10', '2026-12-11', '2026-12-12'] as $index => $date) {
        inTenant($t->a->property, fn () => app(PostRoomCharge::class)->handle(null, $reservation, $date));
        $remaining = 2 - $index;
        $this->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page
            ->where('totals.0.due', 0)->where('totals.0.status', 'paid')
            ->where('totals.0.estimate', $remaining === 0 ? null : ['nights' => $remaining, 'amount' => $remaining * ($room + 2 * $levy)]));
    }
    // The original receipt is a snapshot; posting nights never rewrites it.
    $this->get(route('receipts.show', $receipt))->assertInertia(fn (Assert $page) => $page->where('summary.estimate.amount', $total));
})->with([
    'TZS' => ['TZS', 220000, 12775],
    'USD' => ['USD', 3000, 500],
    'EUR' => ['EUR', 3000, 495],
]);

// protects=date-specific added taxes are counted once while VAT inside the price is not added again.
test('upfront totals use each nights effective tax rules', function () {
    [$t, $reservation] = dueStay();
    inTenant($t->a->property, function () use ($t) {
        app(CreateTaxRule::class)->handle($t->a->owner, [
            'name' => 'VAT', 'kind' => 'percentage', 'rate' => 1500, 'amount' => null, 'currency' => null,
            'inclusive' => true, 'applies_to' => 'room', 'rounding' => 'half_up',
            'effective_from' => '2026-12-10', 'effective_to' => null,
        ]);
        app(CreateTaxRule::class)->handle($t->a->owner, [
            'name' => 'Service charge', 'kind' => 'percentage', 'rate' => 1000, 'amount' => null, 'currency' => null,
            'inclusive' => false, 'applies_to' => 'room', 'rounding' => 'half_up',
            'effective_from' => '2026-12-11', 'effective_to' => null,
        ]);
    });
    // First night: 100.00 inclusive; second: 100.00 + 8.70 service charge on its net amount.
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page
        ->where('totals.0.estimate', ['nights' => 2, 'amount' => 20870])->where('totals.0.due', 20870)->where('totals.0.charges', 0));
});
