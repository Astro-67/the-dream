<?php

use App\Domains\Billing\Actions\OpenCustomerAccount;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\CashierShift;
use App\Domains\Billing\Models\CustomerAccount;
use App\Domains\Billing\Models\CustomerAccountSequence;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Billing\Models\Receipt;
use App\Domains\Billing\PreventArchivingWithOpenBalances;
use App\Domains\FiscalReceipting\Models\IssuedDocument;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Subscriptions\Models\Subscription;
use App\Support\Outbox\OutboxMessage;
use App\Support\Tenancy\PropertyPurge;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
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

test('an account cannot be opened while the subscription blocks new sales', function (string $status) {
    $tenant = twoTenants()->a;
    inTenant($tenant->property, fn () => Subscription::query()->sole()->update(['status' => $status]));
    $this->actingAs($tenant->owner)->postJson(route('customer-accounts.store'), [
        '_property' => $tenant->property->id, 'kind' => 'event', 'customer_name' => 'Conference', 'currency' => 'USD',
    ])->assertUnprocessable()->assertJsonValidationErrors('subscription');
    expect(inTenant($tenant->property, fn () => CustomerAccount::query()->count()))->toBe(0);
})->with(['suspended', 'cancelled']);

test('a folio must have exactly one owner in its own property and one currency per owner', function (string $invalid) {
    $tenants = twoTenants();
    [$account, $folio, $reservation] = inTenant($tenants->a->property, function () use ($tenants) {
        $account = app(OpenCustomerAccount::class)->handle($tenants->a->owner, [
            'kind' => 'day_use', 'customer_name' => 'Pool visitor', 'currency' => 'USD',
        ]);
        $reservation = app(CreateReservation::class)->handle($tenants->a->owner, BookingForm::details($tenants->a->owner));

        return [$account, $account->folios()->sole(), $reservation];
    });
    $attributes = match ($invalid) {
        'neither' => ['customer_account_id' => null],
        'both' => ['reservation_id' => $reservation->id],
        'foreign organization' => ['organization_id' => $tenants->b->organization->id, 'property_id' => $tenants->b->property->id],
        'sibling property' => ['property_id' => $tenants->a->otherProperty->id],
        'duplicate currency' => ['id' => (string) Str::ulid()],
    };
    $write = fn () => $invalid === 'duplicate currency'
        ? DB::table('folios')->insert([...$folio->getAttributes(), ...$attributes])
        : DB::table('folios')->where('id', $folio->id)->update($attributes);
    expect($write)->toThrow(QueryException::class);
})->with(['neither', 'both', 'foreign organization', 'sibling property', 'duplicate currency']);

test('archiving checks held account extras even after payment and filters the supplied property', function () {
    $tenant = twoTenants()->a;
    [$account, $folio] = inTenant($tenant->property, function () use ($tenant) {
        $account = app(OpenCustomerAccount::class)->handle($tenant->owner, ['kind' => 'event', 'customer_name' => 'Conference', 'currency' => 'USD']);
        $folio = $account->folios()->sole();
        app(PostExtraCharge::class)->handle($tenant->owner, $folio, ['quantity' => 1, 'unit_price' => 1000]);

        return [$account, $folio];
    });
    $check = new PreventArchivingWithOpenBalances;
    expect($check->blockingReason($tenant->property))->not->toBeNull()
        ->and($check->blockingReason($tenant->otherProperty))->toBeNull();
    inTenant($tenant->property, fn () => app(RecordPayment::class)->handle($tenant->owner, $folio, ['method' => 'bank_transfer', 'amount' => 1000, 'currency' => 'USD']));
    expect($check->blockingReason($tenant->property))->not->toBeNull();
    $this->actingAs($tenant->owner)->post(route('customer-accounts.close', $account), ['_property' => $tenant->property->id])->assertSessionHasNoErrors();
    expect($check->blockingReason($tenant->property))->toBeNull();
});

test('day-use creation can include the first item and the account screens expose separate items payments and receipt status', function () {
    $tenant = twoTenants()->a;
    $this->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id]);
    $this->actingAs($tenant->owner)->post(route('customer-accounts.store'), [
        '_property' => $tenant->property->id, 'kind' => 'day_use', 'customer_name' => 'Cash customer', 'currency' => 'USD',
        'items' => [['category' => 'spa', 'description' => 'MASSAGE', 'quantity' => 1, 'unit_price' => 5000, 'discount' => 500, 'idempotency_key' => 'first']],
    ])->assertSessionHasNoErrors();
    $account = inTenant($tenant->property, fn () => CustomerAccount::query()->sole());
    $this->get(route('customer-accounts.show', $account))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('customer-accounts/show')->where('account.number', 'ACC-000001')
        ->where('folios.0.items.data.0.description', 'MASSAGE')->where('folios.0.summary.balance', 4500)
        ->has('folios.0.payments.data', 0)->where('generalReceipt.items.0.discount', 500));
    $this->get(route('customer-accounts.index', ['kind' => 'day_use', 'status' => 'open']))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('customer-accounts/index')->has('accounts.data', 1)->where('accounts.data.0.customer_name', 'Cash customer'));
    $this->get(route('customer-accounts.index', ['kind' => 'event']))->assertOk()->assertInertia(fn (Assert $page) => $page->has('accounts.data', 0));
});

test('account payments appear in the receipt register and corrections never move charges to a different account', function () {
    $tenant = twoTenants()->a;
    $this->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id]);
    $intent = ['_property' => $tenant->property->id];
    $this->actingAs($tenant->owner);
    foreach (['Pool visitor', 'Conference'] as $name) {
        $this->post(route('customer-accounts.store'), [...$intent, 'kind' => 'day_use', 'customer_name' => $name, 'currency' => 'USD'])->assertSessionHasNoErrors();
    }
    [$first, $second] = inTenant($tenant->property, fn () => CustomerAccount::query()->orderBy('number')->get()->all());
    [$source, $target] = inTenant($tenant->property, fn () => [$first->folios()->sole(), $second->folios()->sole()]);
    $this->post(route('folios.charges.store', $source), [...$intent, 'category' => 'other', 'description' => 'POOL', 'quantity' => 1, 'unit_price' => 1000, 'idempotency_key' => 'pool'])->assertSessionHasNoErrors();
    $charge = inTenant($tenant->property, fn () => Posting::query()->sole());
    $this->postJson(route('postings.transfer', $charge), [...$intent, 'folio_id' => $target->id, 'reason' => 'Different customer'])->assertUnprocessable();
    $this->post(route('folios.payments.store', $source), [...$intent, 'method' => 'bank_transfer', 'amount' => 1000, 'idempotency_key' => 'paid'])->assertSessionHasNoErrors();
    $this->get(route('receipts.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('documents.data.0.guest', 'Pool visitor'));
    $payment = inTenant($tenant->property, fn () => Posting::query()->where('type', 'payment')->sole());
    $this->post(route('postings.reverse', $payment), [...$intent, 'reason' => 'Wrong payment'])->assertRedirect(route('customer-accounts.show', $first))->assertSessionHasNoErrors();
    $this->get(route('customer-accounts.show', $first))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('folios.0.items.data', 1)->has('folios.0.payments.data', 2));
});

test('a refused account receipt can be refiled without issuing a second sale', function () {
    $tenant = twoTenants()->a;
    $intent = ['_property' => $tenant->property->id];
    $this->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id]);
    Http::swap(new Factory);
    Http::fake([VfmsHttp::TEST_URL.'service/' => Http::sequence()
        ->push(['statusCode' => 5000, 'message' => 'Invalid item'])
        ->push(['receiptNumber' => 'RETRY-ACCOUNT-251'])]);
    VfmsHttp::fake();
    $this->actingAs($tenant->owner)->post(route('customer-accounts.store'), [...$intent, 'kind' => 'event', 'customer_name' => 'Conference', 'currency' => 'USD',
        'items' => [['category' => 'other', 'description' => 'HALL', 'quantity' => 1, 'unit_price' => 5000, 'idempotency_key' => 'hall']],
    ])->assertSessionHasNoErrors();
    [$account, $folio] = inTenant($tenant->property, fn () => [CustomerAccount::query()->sole(), Folio::query()->sole()]);
    $this->post(route('folios.payments.store', $folio), [...$intent, 'method' => 'bank_transfer', 'amount' => 5000, 'idempotency_key' => 'paid'])->assertSessionHasNoErrors();
    $this->post(route('customer-accounts.close', $account), $intent)->assertSessionHasNoErrors();
    $message = inTenant($tenant->property, fn () => OutboxMessage::query()->sole());
    expect($message->status)->toBe('rejected');
    $this->post(route('customer-accounts.vfms.refile', [$account, $message]), [...$intent, 'reason' => 'Corrected item'])->assertSessionHasNoErrors();
    expect(inTenant($tenant->property, fn () => $message->fresh()->status))->toBe('delivered');
    expect(inTenant($tenant->property, fn () => OutboxMessage::query()->count()))->toBe(1);
});

test('reversing a closed account extra leaves its issued receipt and reports the fiscal exception', function () {
    $tenant = twoTenants()->a;
    $intent = ['_property' => $tenant->property->id];
    $this->actingAs($tenant->owner)->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id]);
    $this->post(route('customer-accounts.store'), [...$intent, 'kind' => 'event', 'customer_name' => 'Conference', 'currency' => 'USD',
        'items' => [['category' => 'other', 'description' => 'HALL', 'quantity' => 1, 'unit_price' => 5000, 'idempotency_key' => 'hall']],
    ])->assertSessionHasNoErrors();
    [$account, $folio] = inTenant($tenant->property, fn () => [CustomerAccount::query()->sole(), Folio::query()->sole()]);
    $this->post(route('folios.payments.store', $folio), [...$intent, 'method' => 'bank_transfer', 'amount' => 5000, 'idempotency_key' => 'paid'])->assertSessionHasNoErrors();
    $this->post(route('customer-accounts.close', $account), $intent)->assertSessionHasNoErrors();
    $charge = inTenant($tenant->property, fn () => Posting::query()->where('type', 'charge')->sole());
    $this->post(route('postings.reverse', $charge), [...$intent, 'reason' => 'Wrong item'])->assertSessionHasNoErrors();
    $this->get(route('reports.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('fiscalExceptions.0.reservationNumber', 'ACC-000001')
        ->where('fiscalExceptions.0.kind', 'Receipted extra reversed'));
});

test('demo and Mainland customer accounts close without contacting ZRA', function (string $mode) {
    $tenant = twoTenants()->a;
    $property = $mode === 'mainland' ? $tenant->otherProperty : $tenant->property;
    if ($mode === 'demo') {
        DB::table('properties')->where('id', $property->id)->update(['demo' => true]);
        inTenant($property, fn () => $property->refresh());
    }
    $currency = $mode === 'mainland' ? 'TZS' : 'USD';
    $intent = ['_property' => $property->id];
    $this->actingAs($tenant->owner)->post(route('customer-accounts.store'), [...$intent, 'kind' => 'day_use', 'customer_name' => 'Visitor', 'currency' => $currency,
        'items' => [['category' => 'other', 'description' => 'POOL', 'quantity' => 1, 'unit_price' => 1000, 'idempotency_key' => 'pool']],
    ])->assertSessionHasNoErrors();
    [$account, $folio] = inTenant($property, fn () => [CustomerAccount::query()->sole(), Folio::query()->sole()]);
    $this->post(route('folios.payments.store', $folio), [...$intent, 'method' => 'bank_transfer', 'amount' => 1000, 'idempotency_key' => 'paid'])->assertSessionHasNoErrors();
    $this->post(route('customer-accounts.close', $account), $intent)->assertSessionHasNoErrors();
    expect(inTenant($property, fn () => $account->fresh()->status))->toBe('closed');
    expect((new PreventArchivingWithOpenBalances)->blockingReason($property))->toBeNull();
    $this->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $property->id])
        ->get(route('customer-accounts.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('accounts.data.0.receiptStatus', 'Not applicable'));
    Http::assertNothingSent();
})->with(['demo', 'mainland']);

test('demo purge covers customer accounts and their sequences folios and payments', function () {
    $tenant = twoTenants()->a;
    inTenant($tenant->property, function () use ($tenant) {
        DB::table('properties')->where('id', $tenant->property->id)->update(['demo' => true]);
        $tenant->property->refresh();
        $account = app(OpenCustomerAccount::class)->handle($tenant->owner, ['kind' => 'day_use', 'customer_name' => 'Demo visitor', 'currency' => 'USD']);
        $folio = $account->folios()->sole();
        app(PostExtraCharge::class)->handle($tenant->owner, $folio, ['quantity' => 1, 'unit_price' => 1000]);
        app(RecordPayment::class)->handle($tenant->owner, $folio, ['method' => 'bank_transfer', 'amount' => 1000, 'currency' => 'USD']);
        DB::transaction(fn () => PropertyPurge::purge($tenant->property));
        expect(CustomerAccount::query()->count())->toBe(0)->and(Folio::query()->count())->toBe(0)->and(Receipt::query()->count())->toBe(0)
            ->and(CustomerAccountSequence::query()->count())->toBe(0);
    });
});

test('rolling back account ownership refuses to destroy existing account folios', function () {
    $tenant = twoTenants()->a;
    inTenant($tenant->property, fn () => app(OpenCustomerAccount::class)->handle($tenant->owner, ['kind' => 'day_use', 'customer_name' => 'Visitor', 'currency' => 'USD']));
    $migration = require database_path('migrations/2026_11_12_000237_billing_create_customer_accounts.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    expect(inTenant($tenant->property, fn () => CustomerAccount::query()->count()))->toBe(1);
});

test('an event receipts the paying institution rather than a guest document', function (string $type) {
    $tenant = twoTenants()->a;
    $intent = ['_property' => $tenant->property->id];
    $this->actingAs($tenant->owner)->post(route('customer-accounts.store'), [
        ...$intent, 'kind' => 'event', 'customer_name' => 'Conference organiser', 'currency' => 'USD',
        'paying_institution_type' => $type, 'paying_institution_name' => 'Conference payer', 'paying_institution_zrb_number' => 'TEST-ZRB',
        'starts_on' => '2026-12-10', 'ends_on' => '2026-12-11', 'note' => 'Two-day conference without rooms',
        'items' => [['category' => 'other', 'description' => 'HALL', 'quantity' => 2, 'unit_price' => 5000, 'idempotency_key' => 'hall']],
    ])->assertSessionHasNoErrors();
    [$account, $folio] = inTenant($tenant->property, fn () => [CustomerAccount::query()->sole(), Folio::query()->sole()]);
    $this->post(route('folios.payments.store', $folio), [...$intent, 'method' => 'bank_transfer', 'amount' => 10000, 'idempotency_key' => 'paid'])->assertSessionHasNoErrors();
    $this->post(route('customer-accounts.close', $account), $intent)->assertSessionHasNoErrors();
    expect(inTenant($tenant->property, fn () => OutboxMessage::query()->sole()->payload['body']['documentNumber']))->toBe('Conference payer')
        ->and(inTenant($tenant->property, fn () => Reservation::query()->count()))->toBe(0);
})->with(['government', 'business']);

test('an invalid first item rolls back the account folio and number together', function () {
    $tenant = twoTenants()->a;
    $form = ['_property' => $tenant->property->id, 'kind' => 'day_use', 'customer_name' => 'Visitor', 'currency' => 'USD'];
    $this->actingAs($tenant->owner)->postJson(route('customer-accounts.store'), [
        ...$form, 'items' => [['category' => 'spa', 'description' => 'SPA', 'quantity' => 1, 'unit_price' => 1000, 'discount' => 2000, 'idempotency_key' => 'spa']],
    ])->assertUnprocessable()->assertJsonValidationErrors('discount');
    expect(inTenant($tenant->property, fn () => [CustomerAccount::query()->count(), Folio::query()->count(), CustomerAccountSequence::query()->count()]))->toBe([0, 0, 0]);
    $this->post(route('customer-accounts.store'), $form)->assertSessionHasNoErrors();
    expect(inTenant($tenant->property, fn () => CustomerAccount::query()->sole()->number))->toBe('ACC-000001');
});
