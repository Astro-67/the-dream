<?php

use App\Domains\Billing\Actions\OpenFolio;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\RecordAdjustment;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Actions\ReversePosting;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Receipt;
use App\Domains\Reservations\Actions\CreateReservation;
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
