<?php

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Billing\Models\Receipt;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;

test('only the folio property accepted payment methods may be recorded', function () {
    [$t, $folio] = folioForPayments();
    $t->a->property->update(['payment_settings' => ['accepted_methods' => ['bank_transfer']]]);
    $this->actingAs($t->a->owner)
        ->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id]);

    $this->postJson(route('folios.payments.store', $folio), [
        '_property' => $t->a->property->id, 'method' => 'card', 'amount' => 100, 'idempotency_key' => 'disabled',
    ])->assertUnprocessable()->assertJsonValidationErrors('method');
    expect(inTenant($t->a->property, fn () => Posting::query()->count()))->toBe(0);

    $this->post(route('folios.payments.store', $folio), [
        '_property' => $t->a->property->id, 'method' => 'bank_transfer', 'amount' => 100, 'idempotency_key' => 'accepted',
    ])->assertSessionHasNoErrors();
    $this->get(route('reservations.show', inTenant($t->a->property, fn () => Reservation::query()->findOrFail($folio->reservation_id))))
        ->assertInertia(fn (Assert $page) => $page->where('acceptedPaymentMethods', ['bank_transfer'])->where('folios.data.0.balance', -100));
});
use Tests\Support\PropertyForm;
use Tests\Support\TwoTenants;

/** @return array{0: TwoTenants, 1: Folio} */
function folioForPayments(array $reservation = []): array
{
    $t = twoTenants();
    $folio = inTenant($t->a->property, function () use ($t, $reservation) {
        $id = app(CreateReservation::class)->handle($t->a->owner, [...BookingForm::details($t->a->owner), ...$reservation])->id;

        return Folio::query()->where('reservation_id', $id)->sole();
    });

    return [$t, $folio];
}

test('only roles with charges.post record a payment', function (OrganizationRole $role, bool $allowed) {
    [$t, $folio] = folioForPayments();
    $actor = $role === OrganizationRole::Owner ? $t->a->owner : $t->a->member($role);

    $response = $this->actingAs($actor)->post(route('folios.payments.store', $folio), ['_property' => $t->a->property->id, 'method' => 'bank_transfer', 'amount' => 5000, 'reference' => 'TT-1', 'idempotency_key' => 'k-1']);

    $allowed ? $response->assertRedirect() : $response->assertForbidden();
    expect(inTenant($t->a->property, fn () => Posting::query()->count()))->toBe($allowed ? 1 : 0);
    if ($role !== OrganizationRole::Housekeeping) {
        $reservation = inTenant($t->a->property, fn () => Reservation::query()->findOrFail($folio->reservation_id));
        $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
            ->get(route('reservations.show', $reservation))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canRecordPayment', $allowed));
    }
})->with(array_map(fn (OrganizationRole $role) => [$role, in_array($role, [OrganizationRole::Owner, OrganizationRole::FrontDesk, OrganizationRole::Finance], true)], OrganizationRole::cases()));

test('a recorded payment redirects to its receipt, labelled non-fiscal', function () {
    [$t, $folio] = folioForPayments();
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));

    $response = $this->post(route('folios.payments.store', $folio), ['_property' => $t->a->property->id, 'method' => 'card', 'amount' => 2500, 'reference' => 'POS-9', 'card_last_four' => '4242', 'card_brand' => 'visa', 'idempotency_key' => 'k-1']);

    $receipt = inTenant($t->a->property, fn () => Receipt::query()->sole());
    $response->assertRedirect(route('receipts.show', $receipt));
    $this->actingAs($t->a->member(OrganizationRole::Auditor))->get(route('receipts.show', $receipt))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('receipts/show')
        ->where('receipt.number', 'RCT-000001')->where('receipt.label', 'Non-fiscal receipt')
        ->where('receipt.amount', 2500)->where('receipt.currency', 'USD')->where('receipt.method', 'card')
        ->where('receipt.card_last_four', '4242')->where('receipt.vfms_receipt_number', null));
});

test('a resubmitted payment form posts once and issues one receipt', function () {
    [$t, $folio] = folioForPayments();
    $form = ['_property' => $t->a->property->id, 'method' => 'mobile_money', 'amount' => 900, 'idempotency_key' => '01JABCDEFGHJKMNPQRSTVWXYZ0'];
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));

    $this->post(route('folios.payments.store', $folio), $form)->assertSessionHasNoErrors();
    $this->post(route('folios.payments.store', $folio), $form)->assertSessionHasNoErrors();

    expect(inTenant($t->a->property, fn () => [Posting::query()->count(), Receipt::query()->count()]))->toBe([1, 1]);
});

test('the non-fiscal receipt label uses the active translation', function () {
    [$t, $folio] = folioForPayments();
    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->post(route('folios.payments.store', $folio), [
        '_property' => $t->a->property->id, 'method' => 'bank_transfer', 'amount' => 100, 'idempotency_key' => 'translated-receipt',
    ])->assertSessionHasNoErrors();
    $receipt = inTenant($t->a->property, fn () => Receipt::query()->sole());
    app('translator')->addLines(['*.Non-fiscal receipt' => 'Risiti isiyo ya kodi'], 'sw');
    app()->setLocale('sw');

    $this->get(route('receipts.show', $receipt))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('receipt.label', 'Risiti isiyo ya kodi'));
});

test('the payment form refuses a full card number, cash and a missing key', function () {
    [$t, $folio] = folioForPayments();

    $this->actingAs($t->a->owner)->postJson(route('folios.payments.store', $folio), ['_property' => $t->a->property->id, 'method' => 'card', 'amount' => 100, 'card_last_four' => '4111111111111111', 'card_brand' => str_repeat('x', 40)])
        ->assertUnprocessable()->assertJsonValidationErrors(['card_last_four', 'card_brand', 'idempotency_key']);
    $this->postJson(route('folios.payments.store', $folio), ['_property' => $t->a->property->id, 'method' => 'cash', 'amount' => 100, 'idempotency_key' => 'k'])
        ->assertUnprocessable()->assertJsonValidationErrors(['method']);
    expect(inTenant($t->a->property, fn () => Posting::query()->count()))->toBe(0);
});

test('the reservation shows payments, deposits, receipts and the payment collector', function () {
    [$t, $folio] = folioForPayments(['payment_collector' => 'channel', 'channel_collected_amount' => 24000]);
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));
    $this->post(route('folios.payments.store', $folio), ['_property' => $t->a->property->id, 'method' => 'channel_collected', 'reference' => 'BDC-1', 'idempotency_key' => 'a'])->assertSessionHasNoErrors();
    $reservation = inTenant($t->a->property, fn () => Reservation::query()->findOrFail($folio->reservation_id));

    $this->get(route('reservations.show', $reservation))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('reservation.payment_collector', 'channel')->where('reservation.channel_collected_amount', 24000)
        ->where('folios.data.0.balance', -24000)
        ->where('folios.data.0.postings.data.0.type', 'payment')->where('folios.data.0.postings.data.0.category', 'deposit')
        ->where('folios.data.0.postings.data.0.method', 'channel_collected')->where('folios.data.0.postings.data.0.receipt.number', 'RCT-000001')
        ->where('totals', [['currency' => 'USD', 'charges' => 0, 'balance' => -24000]]));
});

test('front desk records the channel-collected amount when booking', function () {
    $t = twoTenants();
    $details = inTenant($t->a->property, fn () => BookingForm::details($t->a->owner));
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));

    $this->post(route('reservations.store'), [...$details, '_property' => $t->a->property->id, 'payment_collector' => 'channel', 'channel_collected_amount' => 15000])->assertSessionHasNoErrors();
    $this->postJson(route('reservations.store'), [...$details, '_property' => $t->a->property->id, 'payment_collector' => 'channel'])->assertJsonValidationErrors('channel_collected_amount');

    expect(inTenant($t->a->property, fn () => Reservation::query()->sole()->channel_collected_amount))->toBe(15000);
});

test('an owner configures the receipt prefix on the property', function () {
    [$owner, , $property] = organizationWithOwner();
    $this->actingAs($owner);

    $this->put(route('properties.update', $property), PropertyForm::payload(['_property' => $property->id, 'receipt_prefix' => 'SD-']))->assertSessionHasNoErrors();
    $this->get(route('properties.edit', $property))->assertInertia(fn (Assert $page) => $page->where('property.receipt_prefix', 'SD-'));
    $this->putJson(route('properties.update', $property), PropertyForm::payload(['_property' => $property->id, 'receipt_prefix' => 'way too long prefix']))->assertJsonValidationErrors('receipt_prefix');

    expect($property->fresh()->receipt_prefix)->toBe('SD-');
});
