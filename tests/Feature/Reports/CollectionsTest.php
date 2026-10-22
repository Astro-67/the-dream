<?php

use App\Domains\Billing\Actions\CloseCashierShift;
use App\Domains\Billing\Actions\OpenCashierShift;
use App\Domains\Billing\Actions\OpenFolio;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\RecordAdjustment;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Actions\ReversePosting;
use App\Domains\Billing\Models\Folio;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Enums\PropertyStatus;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\TenantFixture;

function collectionsFolio(TenantFixture $tenant): Folio
{
    $tenant->property->update(['business_date' => '2026-12-10', 'status' => PropertyStatus::Live]);

    return inTenant($tenant->property, function () use ($tenant) {
        $reservation = app(CreateReservation::class)->handle($tenant->owner, BookingForm::details($tenant->owner));
        $folio = Folio::query()->where('reservation_id', $reservation->id)->sole();
        app(PostExtraCharge::class)->handle($tenant->owner, $folio, ['category' => 'minibar', 'quantity' => 1, 'unit_price' => 1500]);
        foreach (['2026-12-10' => 100, '2026-12-01' => 200, '2026-11-30' => 300, '2025-12-31' => 400] as $date => $amount) {
            $tenant->property->update(['business_date' => $date]);
            app(RecordPayment::class)->handle($tenant->owner, $folio, ['method' => 'card', 'amount' => $amount, 'currency' => 'USD']);
        }
        $tenant->property->update(['business_date' => '2026-12-10']);
        $shillings = app(OpenFolio::class)->handle($tenant->owner, $reservation, 'TZS');
        app(RecordPayment::class)->handle($tenant->owner, $shillings, ['method' => 'card', 'amount' => 50000, 'currency' => 'TZS']);
        app(RecordPayment::class)->handle($tenant->owner, $folio, ['method' => 'bank_transfer', 'amount' => 50, 'currency' => 'USD']);

        return $folio;
    });
}

test('dashboard collections and payment presets agree per method and currency', function (string $period, string $from, string $to, int $usd) {
    $t = twoTenants();
    collectionsFolio($t->a);
    collectionsFolio($t->b);
    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id]);
    $response = $this->get(route('payments.index', ['period' => $period]));
    $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('payments/index')
        ->where('filters.from', $from)->where('filters.to', $to)
        ->where('received.rows.0.method', 'bank_transfer')->where('received.rows.0.currency', 'USD')->where('received.rows.0.payments', 50)
        ->where('received.rows.1.method', 'card')->where('received.rows.1.currency', 'TZS')->where('received.rows.1.payments', 50000)
        ->where('received.rows.2.method', 'card')->where('received.rows.2.currency', 'USD')->where('received.rows.2.payments', $usd));
    $this->get(route('dashboard', ['period' => $period]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('collections.from', $from)->where('collections.to', $to)
        ->where('collections.received', $response->viewData('page')['props']['received'])
        ->where('collections.href', route('payments.index', ['period' => $period])));
})->with([
    ['today', '2026-12-10', '2026-12-10', 100],
    ['this-month', '2026-12-01', '2026-12-31', 300],
    ['this-year', '2026-01-01', '2026-12-31', 600],
]);

test('last month and custom dates filter received but outstanding stays a current snapshot', function () {
    $t = twoTenants();
    $folio = collectionsFolio($t->a);
    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id]);
    $this->get(route('payments.index', ['period' => 'last-month']))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters.from', '2026-11-01')->where('filters.to', '2026-11-30')->where('received.rows.0.payments', 300));
    $this->get(route('payments.index', ['period' => 'custom', 'from' => '2025-12-31', 'to' => '2025-12-31']))->assertOk()->assertInertia(fn (Assert $page) => $page->where('received.rows.0.payments', 400));
    $this->get(route('payments.index', ['view' => 'outstanding', 'period' => 'custom', 'from' => '2020-01-01', 'to' => '2020-01-02']))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters.view', 'outstanding')->has('folios.data', 2)->where('folios.data', fn ($rows) => collect($rows)->contains(fn ($row) => $row['id'] === $folio->id && $row['balance'] === 450)));
});

test('collections combines cashier shifts and retains refunds and reversals', function () {
    $t = twoTenants();
    $folio = collectionsFolio($t->a);
    inTenant($t->a->property, function () use ($t, $folio) {
        $shift = app(OpenCashierShift::class)->handle($t->a->owner, ['USD' => 0]);
        app(RecordPayment::class)->handle($t->a->owner, $folio, ['method' => 'cash', 'amount' => 60, 'currency' => 'USD']);
        app(CloseCashierShift::class)->handle($t->a->owner, $shift, ['USD' => 60], null);
        app(OpenCashierShift::class)->handle($t->a->owner, ['USD' => 0]);
        app(RecordPayment::class)->handle($t->a->owner, $folio, ['method' => 'cash', 'amount' => 70, 'currency' => 'USD']);
        app(RecordAdjustment::class)->handle($t->a->owner, $folio, 'refund', ['amount' => 20, 'method' => 'card', 'reason' => 'Overpaid', 'idempotency_key' => 'collections-refund']);
        $payment = app(RecordPayment::class)->handle($t->a->owner, $folio, ['method' => 'card', 'amount' => 30, 'currency' => 'USD']);
        app(ReversePosting::class)->handle($t->a->owner, $payment, 'Not received');
    });
    $this->actingAs($t->a->member(OrganizationRole::Auditor))
        ->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->get(route('payments.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('received.rows', 4)
        ->where('received.rows.2', ['method' => 'card', 'currency' => 'USD', 'payments' => 130, 'refunds' => 20, 'reversals' => -30, 'net' => 80])
        ->where('received.rows.3', ['method' => 'cash', 'currency' => 'USD', 'payments' => 130, 'refunds' => 0, 'reversals' => 0, 'net' => 130])
        ->where('received.totals', [
            ['currency' => 'TZS', 'payments' => 50000, 'refunds' => 0, 'reversals' => 0, 'net' => 50000],
            ['currency' => 'USD', 'payments' => 310, 'refunds' => 20, 'reversals' => -30, 'net' => 260],
        ]));
});

test('collections validates dates periods and views and preserves report permissions', function () {
    $t = twoTenants();
    collectionsFolio($t->a);
    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id]);
    foreach ([['period' => 'wrong'], ['view' => 'wrong'], ['period' => 'custom'], ['period' => 'custom', 'from' => '2026-02-30', 'to' => '2026-03-01'], ['period' => 'custom', 'from' => '2026-03-02', 'to' => '2026-03-01']] as $query) {
        $this->getJson(route('payments.index', $query))->assertUnprocessable();
    }
    $this->actingAs($t->a->member(OrganizationRole::Housekeeping))->get(route('payments.index'))->assertForbidden();
    $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('collections', null));
});

// protects=each payment is listed once with who, where and how; fails_when=a correction reads as a new payment or another tenant's payment appears.
test('received lists each payment newest first with guest, room and receipt, and corrections stay distinct', function () {
    $t = twoTenants();
    $folio = collectionsFolio($t->a);
    collectionsFolio($t->b);
    inTenant($t->a->property, function () use ($t, $folio) {
        $payment = app(RecordPayment::class)->handle($t->a->owner, $folio, ['method' => 'card', 'amount' => 30, 'currency' => 'USD', 'reference' => 'POS-77']);
        app(ReversePosting::class)->handle($t->a->owner, $payment, 'Not received');
    });
    $this->actingAs($t->a->member(OrganizationRole::Finance))
        ->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->get(route('payments.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('payments.total', 5)->where('payments.data.0.type', 'reversal')->where('payments.data.0.amount', -30)
        ->where('payments.data.1.type', 'payment')->where('payments.data.1.reference', 'POS-77')->where('payments.data.1.amount', 30)
        ->where('payments.data.1.guest', 'Travel Booker')->where('payments.data.1.room', null)->where('payments.data.1.processed_by', $t->a->owner->name)
        ->where('payments.data.1.receipt.number', fn (string $number) => str_starts_with($number, 'RCT-'))
        ->where('payments.data', fn ($rows) => collect($rows)->every(fn ($row) => $row['business_date'] === '2026-12-10')));
});

test('the payments list keeps fifty rows a page and the outstanding view has none', function () {
    $t = twoTenants();
    $folio = collectionsFolio($t->a);
    inTenant($t->a->property, function () use ($t, $folio) {
        foreach (range(1, 50) as $index) {
            app(RecordPayment::class)->handle($t->a->owner, $folio, ['method' => 'bank_transfer', 'amount' => $index, 'currency' => 'USD']);
        }
    });
    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id]);
    $this->get(route('payments.index', ['payments_page' => 2]))->assertInertia(fn (Assert $page) => $page
        ->where('payments.total', 53)->has('payments.data', 3)->where('payments.next_page_url', null));
    $this->get(route('payments.index', ['view' => 'outstanding']))->assertInertia(fn (Assert $page) => $page->where('payments', null));
});

// protects=the date range follows the property's own business dates; fails_when=a payment just after UTC midnight lands on the wrong day.
test('payments are listed on the property business date, not the UTC date they were posted', function () {
    $t = twoTenants();
    $t->a->property->update(['timezone' => 'Africa/Dar_es_Salaam']);
    $folio = collectionsFolio($t->a);
    // 22:30 UTC on 9 Dec is already 10 Dec in Dar es Salaam, and the business date is 10 Dec.
    $this->travelTo(CarbonImmutable::parse('2026-12-09 22:30:00', 'UTC'));
    inTenant($t->a->property, fn () => app(RecordPayment::class)->handle($t->a->owner, $folio, ['method' => 'mobile_money', 'amount' => 11, 'currency' => 'USD']));
    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id]);

    $this->get(route('payments.index', ['period' => 'custom', 'from' => '2026-12-10', 'to' => '2026-12-10']))->assertInertia(fn (Assert $page) => $page
        ->where('payments.data', fn ($rows) => collect($rows)->contains(fn ($row) => $row['amount'] === 11 && $row['business_date'] === '2026-12-10')));
    $this->get(route('payments.index', ['period' => 'custom', 'from' => '2026-12-09', 'to' => '2026-12-09']))->assertInertia(fn (Assert $page) => $page
        ->where('payments.data', fn ($rows) => collect($rows)->doesntContain(fn ($row) => $row['amount'] === 11)));
});
