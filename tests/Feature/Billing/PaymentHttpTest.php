<?php

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Billing\Models\Receipt;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;

test('only the folio property accepted payment methods may be recorded', function (string $method, string $disabled) {
    [$t, $folio] = folioForPayments();
    $t->a->property->update(['payment_settings' => ['accepted_methods' => [$method]]]);
    $this->actingAs($t->a->owner)
        ->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id]);

    $this->postJson(route('folios.payments.store', $folio), [
        '_property' => $t->a->property->id, 'method' => $disabled, 'amount' => 100, 'idempotency_key' => 'disabled',
    ])->assertUnprocessable()->assertJsonValidationErrors('method');
    expect(inTenant($t->a->property, fn () => Posting::query()->count()))->toBe(0);

    $this->post(route('folios.payments.store', $folio), [
        '_property' => $t->a->property->id, 'method' => $method, 'amount' => 100, 'idempotency_key' => 'accepted',
    ])->assertSessionHasNoErrors();
    $this->get(route('reservations.show', inTenant($t->a->property, fn () => Reservation::query()->findOrFail($folio->reservation_id))))
        ->assertInertia(fn (Assert $page) => $page->where('acceptedPaymentMethods', [$method])->where('folios.data.0.balance', -100)
            ->where('folios.data.0.postings.data.0.method', $method));
})->with([['bank_transfer', 'other'], ['other', 'card']]);

test('Other payments and linked refunds appear on the receipt and payments report', function () {
    [$t, $folio] = folioForPayments();
    $this->actingAs($t->a->owner)
        ->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id]);

    $this->post(route('folios.payments.store', $folio), [
        '_property' => $t->a->property->id, 'method' => 'other', 'amount' => 500, 'idempotency_key' => 'other-payment',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $receipt = inTenant($t->a->property, fn () => Receipt::query()->sole());
    $this->get(route('receipts.show', $receipt))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('receipt.method', 'other')->where('receipt.amount', 500));

    $this->post(route('folios.refunds.store', $folio), [
        '_property' => $t->a->property->id, 'method' => 'other', 'amount' => 100, 'reason' => 'Deposit returned',
        'payment_posting_id' => $receipt->posting_id, 'idempotency_key' => 'other-refund',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->get(route('receipts.show', $receipt))->assertInertia(fn (Assert $page) => $page
        ->where('receipt.method', 'other')->where('receipt.amount', 500)
        ->where('corrections.0.type', 'refund')->where('corrections.0.amount', 100));
    $this->get(route('payments.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('payments.total', 2)
        ->where('payments.data.0.type', 'refund')->where('payments.data.0.method', 'other')->where('payments.data.0.amount', -100)
        ->where('payments.data.1.type', 'payment')->where('payments.data.1.method', 'other')->where('payments.data.1.amount', 500)
        ->where('payments.data.1.receipt.number', $receipt->number)
        ->where('received.rows.0.method', 'other')->where('received.rows.0.net', 400));
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
})->with(array_map(fn (OrganizationRole $role) => [$role, in_array($role, [OrganizationRole::Owner, OrganizationRole::PropertyManager, OrganizationRole::FrontDesk, OrganizationRole::Finance], true)], OrganizationRole::cases()));

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

test('the dialog payment route accepts partial, full and excess deposits and opens the receipt', function (int $amount, int $due, string $status) {
    [$t, $folio] = folioForPayments(['adults' => 1, 'children' => 0]);
    $reservation = inTenant($t->a->property, fn () => Reservation::query()->findOrFail($folio->reservation_id));
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))
        ->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id]);
    $this->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page
        ->where('canRecordPayment', true)->where('totals.0.due', 20000)->where('totals.0.status', 'unpaid'));

    $form = ['_property' => $t->a->property->id, 'method' => 'bank_transfer', 'amount' => $amount, 'reference' => 'DIALOG-150', 'idempotency_key' => 'dialog-150'];
    $response = $this->post(route('folios.payments.store', $folio), $form)->assertRedirect()->assertSessionHasNoErrors();
    $this->get($response->headers->get('Location'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('receipts/show')->where('receipt.amount', $amount)->where('receipt.deposit', true)
        ->where('receipt.reference', 'DIALOG-150'));
    $this->post(route('folios.payments.store', $folio), $form)->assertRedirect($response->headers->get('Location'))->assertSessionHasNoErrors();
    $this->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page
        ->where('totals.0.due', $due)->where('totals.0.status', $status)
        ->where('folios.data.0.payments.total', 1)->where('folios.data.0.payments.data.0.amount', -$amount));
})->with([[5000, 15000, 'partially_paid'], [20000, 0, 'paid'], [25000, -5000, 'paid']]);

test('payment and refund routes remain available when nothing is due', function () {
    [$t, $folio] = folioForPayments(['adults' => 1, 'children' => 0]);
    $reservation = inTenant($t->a->property, fn () => Reservation::query()->findOrFail($folio->reservation_id));
    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id]);
    $payment = ['_property' => $t->a->property->id, 'method' => 'bank_transfer', 'amount' => 20000, 'idempotency_key' => 'paid-150'];
    $this->post(route('folios.payments.store', $folio), $payment)->assertRedirect()->assertSessionHasNoErrors();
    $this->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page
        ->where('totals.0.due', 0)->where('canRecordPayment', true)->where('canRefund', true));
    $receipt = inTenant($t->a->property, fn () => Receipt::query()->sole());
    $this->post(route('folios.refunds.store', $folio), [
        '_property' => $t->a->property->id, 'method' => 'bank_transfer', 'amount' => 100, 'reason' => 'Return deposit',
        'payment_posting_id' => $receipt->posting_id, 'idempotency_key' => 'refund-150',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('folios.payments.store', $folio), [...$payment, 'amount' => 100, 'idempotency_key' => 'settled-again-150'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page->where('totals.0.due', 0));
    $this->post(route('folios.payments.store', $folio), [...$payment, 'amount' => 500, 'idempotency_key' => 'credit-150'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page
        ->where('totals.0.due', -500)->where('totals.0.status', 'paid'));
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
        ->where('totals.0.currency', 'USD')->where('totals.0.charges', 0)->where('totals.0.net_payments', 24000)->where('totals.0.balance', -24000));
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

test('a bookerless reservation receipt identifies the lead guest', function () {
    [$t, $folio] = folioForPayments(['booker_id' => null, 'lead_guest' => ['first_name' => 'Asha', 'last_name' => 'Ali', 'nationality' => 'TZ']]);
    $response = $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)
        ->post(route('folios.payments.store', $folio), ['_property' => $t->a->property->id, 'method' => 'bank_transfer', 'amount' => 100, 'idempotency_key' => 'inline-receipt']);
    $response->assertRedirect()->assertSessionHasNoErrors();
    $this->get($response->headers->get('Location'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('booker.name', 'Asha Ali'));
});
