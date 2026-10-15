<?php

use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\Folio;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\Property;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\TenantFixture;

/** A folio in $property charged 1500 and paid 400 by card on its business date of 10 December. */
function financeReportPayment(TenantFixture $tenant, Property $property): Folio
{
    $property->update(['business_date' => '2026-12-10']);

    return inTenant($property, function () use ($tenant) {
        $id = app(CreateReservation::class)->handle($tenant->owner, BookingForm::details($tenant->owner))->id;
        $folio = Folio::query()->where('reservation_id', $id)->sole();
        app(PostExtraCharge::class)->handle($tenant->owner, $folio, ['category' => 'minibar', 'quantity' => 1, 'unit_price' => 1500]);
        app(RecordPayment::class)->handle($tenant->owner, $folio, ['method' => 'card', 'amount' => 400, 'currency' => 'USD']);

        return $folio;
    });
}

test('an auditor reads the current property folio balances and payments for the selected dates', function () {
    $t = twoTenants();
    $folio = financeReportPayment($t->a, $t->a->property);
    financeReportPayment($t->a, $t->a->otherProperty);

    $this->actingAs($t->a->member(OrganizationRole::Auditor))
        ->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->get(route('reports.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('reports/index')
        ->has('finance.folios.data', 1)
        ->where('finance.folios.total', 1)
        ->where('finance.folios.data.0.id', $folio->id)
        ->where('finance.folios.data.0.balance', 1100)
        ->where('finance.openAfterDeparture', [])
        ->where('finance.payments.totals', [['currency' => 'USD', 'payments' => 400, 'refunds' => 0, 'reversals' => 0, 'net' => 400]]));
});

test('payments outside the selected dates are left out but folio balances stay a current snapshot', function () {
    $t = twoTenants();
    financeReportPayment($t->a, $t->a->property);

    $this->actingAs($t->a->owner)
        ->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->get(route('reports.index', ['from' => '2026-12-11', 'to' => '2026-12-12']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('finance.folios.data.0.balance', 1100)
        ->where('finance.payments', ['rows' => [], 'totals' => []]));
});
