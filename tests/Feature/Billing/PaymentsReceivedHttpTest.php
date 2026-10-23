<?php

use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Models\Folio;
use App\Domains\Reservations\Actions\CreateReservation;
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
