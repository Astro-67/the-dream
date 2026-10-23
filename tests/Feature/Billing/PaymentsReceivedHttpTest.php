<?php

use App\Domains\Billing\Actions\OpenFolio;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Models\Folio;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;

test('a folio lists deposits payments and refunds independently of its charge pages', function () {
    $t = twoTenants();
    $this->travelTo(CarbonImmutable::parse('2026-06-01T09:30:00Z'));
    [$reservation, $folio] = inTenant($t->a->property, function () use ($t) {
        $reservation = app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
        $folio = Folio::query()->sole();
        for ($index = 0; $index < 50; $index++) {
            app(PostExtraCharge::class)->handle($t->a->owner, $folio, ['category' => 'minibar', 'quantity' => 1, 'unit_price' => 100]);
        }

        return [$reservation, $folio];
    });
    $this->actingAs($t->a->owner)->withSession([
        'tenancy.organization_id' => $t->a->organization->id,
        'tenancy.property_id' => $t->a->property->id,
    ]);
    $deposit = $this->post(route('folios.payments.store', $folio), [
        '_property' => $t->a->property->id, 'method' => 'bank_transfer', 'amount' => 2500,
        'reference' => 'BANK-151', 'idempotency_key' => 'deposit-151',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('folios.refunds.store', $folio), [
        '_property' => $t->a->property->id, 'method' => 'bank_transfer', 'amount' => 500,
        'reason' => 'Deposit correction', 'reference' => 'REFUND-151', 'idempotency_key' => 'refund-151',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->get(route('reservations.show', $reservation))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('folios.data.0.postings.data', 50)
        ->has('folios.data.0.payments.data', 2)
        ->where('folios.data.0.payments.data.0.type', 'refund')
        ->where('folios.data.0.payments.data.0.amount', 500)
        ->where('folios.data.0.payments.data.0.reference', 'REFUND-151')
        ->where('folios.data.0.payments.data.1.category', 'deposit')
        ->where('folios.data.0.payments.data.1.method', 'bank_transfer')
        ->where('folios.data.0.payments.data.1.amount', -2500)
        ->where('folios.data.0.payments.data.1.actor', $t->a->owner->name)
        ->where('folios.data.0.payments.data.1.posted_at', '2026-06-01T09:30:00+00:00')
        ->where('folios.data.0.payments.data.1.reference', 'BANK-151')
        ->where('folios.data.0.payments.data.1.receipt.number', 'RCT-000001')
        ->where('folios.data.0.payments.data.1.receipt.id', basename($deposit->headers->get('Location'))));
});

test('the folio report link includes historical payments across reservation folios', function () {
    $t = twoTenants();
    [$reservation, $dollars, $shillings] = inTenant($t->a->property, function () use ($t) {
        $reservation = app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));

        return [$reservation, Folio::query()->sole(), app(OpenFolio::class)->handle($t->a->owner, $reservation, 'TZS')];
    });
    $this->actingAs($t->a->owner)->withSession([
        'tenancy.organization_id' => $t->a->organization->id,
        'tenancy.property_id' => $t->a->property->id,
    ]);
    $this->get(route('reservations.show', $reservation))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('folios.data.0.payments.data', [])->where('folios.data.1.payments.data', []));
    foreach ([[$dollars, '2026-06-01', 2500], [$shillings, '2026-06-03', 50000]] as [$folio, $date, $amount]) {
        $t->a->property->update(['business_date' => $date]);
        $this->post(route('folios.payments.store', $folio), [
            '_property' => $t->a->property->id, 'method' => 'bank_transfer', 'amount' => $amount, 'idempotency_key' => $date,
        ])->assertRedirect()->assertSessionHasNoErrors();
    }
    $t->a->property->update(['business_date' => '2026-12-10']);

    $response = $this->get(route('reservations.show', $reservation))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('folios.data.0.payments.data', 1)->where('folios.data.0.payments.data.0.amount', -2500)
        ->has('folios.data.1.payments.data', 1)->where('folios.data.1.payments.data.0.amount', -50000)
        ->has('paymentsReportUrl'));
    $this->get($response->viewData('page')['props']['paymentsReportUrl'])->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters.reservation', $reservation->id)
        ->where('filters.from', '2026-06-01')->where('filters.to', '2026-06-03')
        ->has('payments.data', 2)->has('received.totals', 2));

    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get(route('reservations.show', $reservation))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('paymentsReportUrl', null));
});
