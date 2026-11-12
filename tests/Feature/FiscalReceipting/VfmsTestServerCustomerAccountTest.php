<?php

use App\Domains\Billing\Models\CustomerAccount;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

test('day use with a discount and a two-day event close through ZRA test-server general receipts', function (string $kind) {
    $url = rtrim((string) config('services.vfms.test_url'), '/');
    expect(parse_url($url, PHP_URL_HOST))->toBe('102.223.7.131');
    Http::preventStrayRequests();
    Http::allowStrayRequests([$url.'/*']);
    $tenant = twoTenants()->a;
    expect(inTenant($tenant->property, fn () => VfmsCredential::for($tenant->property)->mode))->toBe(VfmsMode::Sandbox);
    $this->actingAs($tenant->owner)->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id]);
    $intent = ['_property' => $tenant->property->id];
    $run = 'ACC'.Str::upper(Str::random(6));
    $start = $tenant->property->business_date;
    $this->post(route('customer-accounts.store'), [
        ...$intent, 'kind' => $kind, 'customer_name' => 'Test '.($kind === 'day_use' ? 'Day Use ' : 'Event ').$run,
        'currency' => 'USD',
        ...($kind === 'event' ? ['starts_on' => $start->toDateString(), 'ends_on' => $start->addDay()->toDateString()] : []),
        'items' => [[
            'category' => 'other', 'description' => $kind === 'day_use' ? 'POOL AND LUNCH' : 'CONFERENCE HALL',
            'quantity' => 2, 'unit_price' => 2500, 'discount' => 500, 'idempotency_key' => 'first-'.$run,
        ]],
    ])->assertSessionHasNoErrors();
    [$account, $folio] = inTenant($tenant->property, fn () => [CustomerAccount::query()->sole(), Folio::query()->sole()]);
    expect(inTenant($tenant->property, fn () => OutboxMessage::query()->where('integration', 'vfms')->count()))->toBe(0);
    if ($kind === 'event') {
        $this->post(route('night-audit.store'), [...$intent, 'business_date' => $start->toDateString()])->assertSessionHasNoErrors();
        $this->post(route('folios.charges.store', $folio), [
            ...$intent, 'category' => 'restaurant', 'description' => 'SECOND DAY CATERING', 'quantity' => 3,
            'unit_price' => 1000, 'idempotency_key' => 'second-'.$run,
        ])->assertSessionHasNoErrors();
        expect(inTenant($tenant->property, fn () => Posting::query()->where('type', 'charge')->pluck('business_date')->map->toDateString()->unique()->count()))->toBe(2);
    }
    $this->post(route('folios.payments.store', $folio), [
        ...$intent, 'method' => 'bank_transfer', 'amount' => $kind === 'event' ? 7500 : 4500, 'idempotency_key' => 'payment-'.$run,
    ])->assertSessionHasNoErrors();
    $this->post(route('customer-accounts.close', $account), $intent)->assertSessionHasNoErrors();
    $message = inTenant($tenant->property, fn () => OutboxMessage::query()->where('integration', 'vfms')->with('attempts')->sole());
    $evidence = [
        'kind' => $kind, 'status' => $message->status, 'references' => $message->getAttribute('provider_references'), 'body' => $message->payload['body'],
        'attempts' => $message->attempts->map(fn ($attempt) => ['outcome' => $attempt->outcome, 'response' => $attempt->response_body])->all(),
    ];
    file_put_contents(storage_path("app/private/vfms-test-server-account-{$kind}.json"), json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    expect($message->status)->toBe('delivered')->and($message->getAttribute('provider_references')['receiptNumber'] ?? null)->toBeString()
        ->and($message->payload['body']['type'])->toBe('WS')
        ->and($message->payload['body']['service'][0]['discount'])->toBe(5)
        ->and(inTenant($tenant->property, fn () => $account->fresh()->status))->toBe('closed');
})->with(['day_use', 'event'])->skip(getenv('VFMS_TEST_SERVER_RUN') !== '1', 'Real ZRA test-server run: set VFMS_TEST_SERVER_RUN=1.');
