<?php

use App\Domains\Billing\Models\CustomerAccount;
use App\Domains\Billing\Models\Folio;
use Illuminate\Support\Facades\Http;
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
