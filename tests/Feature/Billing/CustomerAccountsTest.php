<?php

use App\Domains\Billing\Models\CashierShift;
use App\Domains\Billing\Models\CustomerAccount;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Receipt;
use App\Domains\FiscalReceipting\Models\IssuedDocument;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\VfmsHttp;

beforeEach(function () {
    VfmsHttp::fake();
});

test('a cashier opens a numbered day-use account with its own folio', function () {
    $tenant = twoTenants()->a;

    $response = $this->actingAs($tenant->owner)->post(route('customer-accounts.store'), [
        '_property' => $tenant->property->id, 'kind' => 'day_use', 'customer_name' => 'Pool visitor', 'currency' => 'USD',
    ])->assertSessionHasNoErrors();

    inTenant($tenant->property, function () use ($response) {
        $account = CustomerAccount::query()->sole();
        $response->assertRedirect(route('customer-accounts.show', $account));
        expect($account->number)->toBe('ACC-000001')
            ->and($account->status)->toBe('open')
            ->and($account->customer_name)->toBe('Pool visitor');
        $folio = Folio::query()->sole();
        expect($folio->reservation_id)->toBeNull()
            ->and($folio->customer_account_id)->toBe($account->id)
            ->and($folio->currency->value)->toBe('USD');
    });
    Http::assertNothingSent();
});

test('account extras are held and closing a settled account issues one discounted general receipt', function () {
    $tenant = twoTenants()->a;
    $this->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id]);
    $intent = ['_property' => $tenant->property->id];
    $this->actingAs($tenant->owner)->post(route('customer-accounts.store'), [
        ...$intent, 'kind' => 'day_use', 'customer_name' => 'Pool visitor', 'currency' => 'USD',
    ])->assertSessionHasNoErrors();
    [$account, $folio] = inTenant($tenant->property, fn () => [CustomerAccount::query()->sole(), Folio::query()->sole()]);
    $this->post(route('folios.charges.store', $folio), [
        ...$intent, 'category' => 'other', 'description' => 'POOL AND LUNCH', 'quantity' => 2,
        'unit_price' => 2500, 'discount' => 500, 'idempotency_key' => 'pool',
    ])->assertRedirect(route('customer-accounts.show', $account))->assertSessionHasNoErrors();
    Http::assertNothingSent();
    $this->postJson(route('customer-accounts.close', $account), $intent)->assertUnprocessable()->assertJsonValidationErrors('balance');
    $this->post(route('cashier-shifts.store'), [...$intent, 'floats' => ['USD' => 0]])->assertSessionHasNoErrors();
    $this->post(route('folios.payments.store', $folio), [
        ...$intent, 'method' => 'cash', 'amount' => 4500, 'idempotency_key' => 'cash',
    ])->assertSessionHasNoErrors();
    $receipt = inTenant($tenant->property, fn () => Receipt::query()->sole());
    expect(inTenant($tenant->property, fn () => CashierShift::query()->sole()->expectedCash()))->toEqual(['USD' => 4500]);
    $this->get(route('receipts.show', $receipt))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('booker.name', 'Pool visitor')->where('receipt.amount', 4500)->where('customerAccount.number', 'ACC-000001'));
    $this->post(route('customer-accounts.close', $account), $intent)->assertSessionHasNoErrors();
    $this->post(route('customer-accounts.close', $account), $intent)->assertSessionHasNoErrors();
    inTenant($tenant->property, function () use ($account, $folio) {
        expect($account->fresh()->status)->toBe('closed')->and($folio->balance()->amount)->toBe(0);
        $message = OutboxMessage::query()->where('event', 'generalReceipt')->sole();
        expect($message->status)->toBe('delivered')
            ->and($message->subject_type)->toBe($account->getMorphClass())
            ->and($message->payload['body'])->toMatchArray([
                'documentNumber' => 'Pool visitor', 'type' => 'WS', 'currency' => 'USD',
                'service' => [['itemName' => 'POOL AND LUNCH', 'price' => 25, 'quantity' => 2, 'discount' => 5]],
            ]);
        $document = IssuedDocument::query()->sole();
        expect($document->customer_account_id)->toBe($account->id)->and($document->reservation_id)->toBeNull();
    });
    $this->postJson(route('folios.charges.store', $folio), [
        ...$intent, 'category' => 'other', 'description' => 'LATE', 'quantity' => 1, 'unit_price' => 100, 'idempotency_key' => 'late',
    ])->assertUnprocessable()->assertJsonValidationErrors('account');
});
