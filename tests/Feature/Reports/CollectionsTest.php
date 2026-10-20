<?php

use App\Domains\Billing\Actions\OpenFolio;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\Folio;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Enums\PropertyStatus;
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
