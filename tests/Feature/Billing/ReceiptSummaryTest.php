<?php

use App\Domains\Billing\Actions\OpenFolio;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\PostRoomCharge;
use App\Domains\Billing\Actions\RecordAdjustment;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Actions\ReversePosting;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Receipt;
use App\Domains\Rates\Actions\SetRatePrices;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\TwoTenants;

/** Property A of two tenants with a USD 50.00 extra on a reservation's folio. */
function receiptFolio(): array
{
    $t = twoTenants();
    $folio = inTenant($t->a->property, function () use ($t) {
        app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
        $folio = Folio::query()->sole();
        app(PostExtraCharge::class)->handle($t->a->owner, $folio, ['category' => 'laundry', 'quantity' => 1, 'unit_price' => 5000]);

        return $folio;
    });

    return [$t, $folio];
}

function pay(TwoTenants $t, Folio $folio, int $amount, string $key): Receipt
{
    return inTenant($t->a->property, fn () => app(RecordPayment::class)->handle($t->a->owner, $folio, ['method' => 'bank_transfer', 'amount' => $amount, 'currency' => 'USD', 'idempotency_key' => $key])->receipt);
}

function receiptThreeNightStay(): array
{
    $t = twoTenants();
    $reservation = inTenant($t->a->property, function () use ($t) {
        $details = BookingForm::details($t->a->owner);
        app(SetRatePrices::class)->handle($t->a->owner, RatePlan::query()->findOrFail($details['rate_plan_id']), [
            'room_type_id' => $details['room_type_id'], 'from' => '2026-12-12', 'to' => '2026-12-12',
            'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'base_price' => 10000, 'extra_adult' => 0, 'extra_child' => 0,
        ]);

        return app(CreateReservation::class)->handle($t->a->owner, [...$details, 'departure' => '2026-12-13', 'adults' => 1, 'children' => 0]);
    });

    return [$t, $reservation, inTenant($t->a->property, fn () => Folio::query()->sole())];
}

test('a deposit before night audit has no posted room charges while the stay has an estimate', function () {
    [$t, $reservation, $folio] = receiptThreeNightStay();
    $receipt = pay($t, $folio, 5000, 'before-audit');

    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->get(route('receipts.show', $receipt))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('receipt.deposit', true)->where('summary.room', 0)->where('summary.charges', 0)->where('summary.balance', -5000));
    $this->get(route('reservations.show', $reservation))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('totals.0.estimate', ['nights' => 3, 'amount' => 30000]));
});

test('a receipt freezes the unposted room estimate at payment time', function () {
    [$t, $reservation, $folio] = receiptThreeNightStay();
    $receipt = pay($t, $folio, 5000, 'estimated-deposit');
    inTenant($t->a->property, function () use ($t, $reservation) {
        $reservation->update(['status' => ReservationStatus::CheckedIn]);
        foreach (['2026-12-10', '2026-12-11', '2026-12-12'] as $date) {
            app(PostRoomCharge::class)->handle($t->a->owner, $reservation, $date);
        }
    });

    expect(pay($t, $folio, 5000, 'estimated-deposit')->id)->toBe($receipt->id);
    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->get(route('receipts.show', $receipt))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('summary.estimate', ['nights' => 3, 'amount' => 30000, 'currency' => 'USD'])
        ->where('summary.room', 0)->where('summary.charges', 0)->where('summary.balance', -5000));
});

test('only nights not yet posted are estimated on a receipt', function (int $postedNights, ?array $estimate) {
    [$t, $reservation, $folio] = receiptThreeNightStay();
    inTenant($t->a->property, function () use ($t, $reservation, $postedNights) {
        $reservation->update(['status' => ReservationStatus::CheckedIn]);
        foreach (array_slice(['2026-12-10', '2026-12-11', '2026-12-12'], 0, $postedNights) as $date) {
            app(PostRoomCharge::class)->handle($t->a->owner, $reservation, $date);
        }
    });
    $receipt = pay($t, $folio, 5000, 'after-audit');

    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->get(route('receipts.show', $receipt))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('summary.estimate', $estimate)->where('summary.room', $postedNights * 10000));
})->with([
    'one night posted' => [1, ['nights' => 2, 'amount' => 20000, 'currency' => 'USD']],
    'every night posted' => [3, null],
]);

test('a receipt snapshot without an estimate is not backfilled from the reservation', function () {
    [$t, $folio] = receiptFolio();
    $receipt = pay($t, $folio, 1000, 'before-estimates');
    $summary = $receipt->summary;
    unset($summary['estimate']);
    inTenant($t->a->property, fn () => DB::table('receipts')->where('id', $receipt->id)->update(['summary' => json_encode($summary, JSON_THROW_ON_ERROR)]));

    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->get(route('receipts.show', $receipt))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->missing('summary.estimate')->where('summary.charges', 5000)->where('summary.balance', 4000));
});

// protects=each receipt keeps the balance of its own moment; fails_when=a later payment or correction rewrites an earlier snapshot.
test('each receipt snapshots its folio at payment time and later postings never change it', function () {
    [$t, $folio] = receiptFolio();
    $first = pay($t, $folio, 2000, 'first');
    $second = pay($t, $folio, 1000, 'second');
    $retry = pay($t, $folio, 1000, 'second');
    inTenant($t->a->property, function () use ($t, $folio, $first) {
        app(ReversePosting::class)->handle($t->a->owner, $first->posting, 'Bounced transfer');
        app(RecordAdjustment::class)->handle($t->a->owner, $folio, 'refund', ['amount' => 500, 'reason' => 'Goodwill', 'idempotency_key' => 'refund', 'method' => 'bank_transfer']);
    });

    $snapshots = inTenant($t->a->property, fn () => Receipt::query()->orderBy('sequence')->get());
    expect($retry->id)->toBe($second->id)->and($snapshots)->toHaveCount(2)
        ->and($snapshots[0]->summary)->toMatchArray(['payment_posting_id' => $first->posting_id, 'currency' => 'USD', 'charges' => 5000, 'net_payments' => 2000, 'balance' => 3000])
        ->and($snapshots[1]->summary)->toMatchArray(['payment_posting_id' => $second->posting_id, 'charges' => 5000, 'net_payments' => 3000, 'balance' => 2000]);

    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get(route('receipts.show', $first))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('receipt.amount', 2000)->where('summary.balance', 3000)->where('summary.charges', 5000)
        ->where('stay.reservation_number', inTenant($t->a->property, fn () => Reservation::query()->sole()->reservation_number))
        ->where('receipt.cashier', $t->a->owner->name)->where('corrections.0.type', 'reversal')->missing('summary.stay'));
});

// protects=a receipt keeps the stay as it was; fails_when=a later room move or date change rewrites an earlier receipt.
test('the receipt shows the stay stored at payment time, not the reservation today', function () {
    [$t, $folio] = receiptFolio();
    $receipt = pay($t, $folio, 1000, 'before-move');
    inTenant($t->a->property, fn () => Reservation::query()->sole()->update(['departure' => '2026-12-15']));

    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->get(route('receipts.show', $receipt))->assertInertia(fn (Assert $page) => $page
        ->where('stay.departure', '2026-12-12')->where('stay.nights', 2)->where('stay.guest', 'Travel Booker'));
});

// protects=a snapshot covers only the paid folio; fails_when=another folio or currency leaks into it.
test('the snapshot covers the paid folio only', function () {
    [$t, $folio] = receiptFolio();
    inTenant($t->a->property, function () use ($t) {
        $other = app(OpenFolio::class)->handle($t->a->owner, Reservation::query()->sole(), 'USD');
        app(PostExtraCharge::class)->handle($t->a->owner, $other, ['category' => 'minibar', 'quantity' => 1, 'unit_price' => 9900]);
    });

    expect(pay($t, $folio, 1000, 'one')->summary)->toMatchArray(['charges' => 5000, 'balance' => 4000]);
});

// protects=old receipts never show a live balance as historical; fails_when=a missing snapshot is reconstructed.
test('a receipt issued before snapshots shows the payment and says the summary is unavailable', function () {
    [$t, $folio] = receiptFolio();
    $receipt = pay($t, $folio, 1000, 'old');
    inTenant($t->a->property, fn () => DB::table('receipts')->where('id', $receipt->id)->update(['summary' => null]));

    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->get(route('receipts.show', $receipt))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('receipt.amount', 1000)->where('summary', null));
});

// protects=a refund against a payment shows on that payment's receipt, which stays unchanged; fails_when=the refund is unreachable from the receipt or links to a payment it cannot correct.
test('a refund against a payment links to its receipt and leaves the snapshot unchanged', function () {
    [$t, $folio] = receiptFolio();
    $receipt = pay($t, $folio, 2000, 'paid');
    $foreign = inTenant($t->b->property, function () use ($t) {
        app(CreateReservation::class)->handle($t->b->owner, BookingForm::details($t->b->owner));

        return app(RecordPayment::class)->handle($t->b->owner, Folio::query()->sole(), ['method' => 'bank_transfer', 'amount' => 3000, 'currency' => 'USD']);
    });
    $refund = fn (string $key, array $overrides = []) => ['_property' => $t->a->property->id, 'amount' => 500, 'method' => 'bank_transfer', 'reason' => 'Overpaid', 'idempotency_key' => $key, 'payment_posting_id' => $receipt->posting_id, ...$overrides];
    $this->actingAs($t->a->member(OrganizationRole::Finance));

    $this->post(route('folios.refunds.store', $folio), $refund('too-much', ['amount' => 2001]))->assertSessionHasErrors('amount');
    $this->post(route('folios.refunds.store', $folio), $refund('foreign', ['payment_posting_id' => $foreign->id]))->assertSessionHasErrors('payment_posting_id');
    $this->post(route('folios.refunds.store', $folio), $refund('linked'))->assertSessionHasNoErrors();
    $this->post(route('folios.refunds.store', $folio), $refund('again'))->assertSessionHasErrors('payment_posting_id');

    $this->get(route('receipts.show', $receipt))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('receipt.amount', 2000)->where('summary.balance', 3000)
        ->has('corrections', 1)->where('corrections.0.type', 'refund')->where('corrections.0.amount', 500));
    expect(inTenant($t->a->property, fn () => Receipt::query()->sole()->summary))->toMatchArray(['net_payments' => 2000, 'balance' => 3000]);
});
