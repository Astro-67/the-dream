<?php

use App\Domains\Billing\Actions\OpenCustomerAccount;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Models\CustomerAccount;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\VfmsHttp;

beforeEach(function () {
    VfmsHttp::fake();
});

test('account routes isolate sibling properties and a stale property intent never changes a different property', function (string $route) {
    $tenant = twoTenants()->a;
    [$account, $message] = inTenant($tenant->otherProperty, function () use ($tenant) {
        $account = app(OpenCustomerAccount::class)->handle($tenant->owner, ['kind' => 'event', 'customer_name' => 'Sibling conference', 'currency' => 'TZS']);
        $message = OutboxMessage::query()->create([
            'integration' => 'vfms', 'subject_type' => $account->getMorphClass(), 'subject_id' => $account->id,
            'sequence' => 1, 'stream_key' => hash('sha256', $account->id), 'idempotency_key' => 'sibling:'.$account->id,
            'event' => 'generalReceipt', 'payload' => ['customer_account_id' => $account->id], 'status' => 'rejected',
        ]);

        return [$account, $message];
    });
    $this->actingAs($tenant->member(OrganizationRole::FrontDesk))
        ->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id]);
    if ($route === 'index') {
        $this->get(route('customer-accounts.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('accounts.data', 0));
    } elseif ($route === 'store') {
        $this->post(route('customer-accounts.store'), ['_property' => $tenant->otherProperty->id, 'kind' => 'day_use', 'customer_name' => 'Forbidden', 'currency' => 'TZS'])->assertForbidden();
    } elseif ($route === 'show') {
        $this->get(route('customer-accounts.show', $account))->assertNotFound();
    } else {
        $url = route('customer-accounts.'.$route, str_starts_with($route, 'vfms.') ? [$account, $message] : $account);
        $this->post($url, ['_property' => $tenant->property->id, 'reason' => 'Sibling attack'])->assertNotFound();
        $this->post($url, ['_property' => $tenant->otherProperty->id, 'reason' => 'Revoked property intent'])->assertForbidden();
    }
    inTenant($tenant->otherProperty, function () use ($account, $message) {
        expect(CustomerAccount::query()->count())->toBe(1)->and($account->fresh()->status)->toBe('open')
            ->and($message->fresh()->status)->toBe('rejected')->and($message->revisions()->count())->toBe(0);
    });
    Http::assertNothingSent();
})->with(['index', 'store', 'show', 'close', 'vfms.retry', 'vfms.refile']);

test('identical customer names never mix account numbers postings or receipts between properties', function () {
    $tenants = twoTenants();
    $accounts = [];
    foreach ([$tenants->a->property, $tenants->a->otherProperty, $tenants->b->property] as $property) {
        $owner = $property->organization_id === $tenants->a->organization->id ? $tenants->a->owner : $tenants->b->owner;
        $accounts[] = inTenant($property, function () use ($owner, $property) {
            $currency = JurisdictionRules::allowedCurrencies($property)[0]->value;
            $account = app(OpenCustomerAccount::class)->handle($owner, ['kind' => 'day_use', 'customer_name' => 'Same customer', 'currency' => $currency]);
            app(PostExtraCharge::class)->handle($owner, $account->folios()->sole(), ['quantity' => 1, 'unit_price' => 1000]);

            return $account;
        });
    }
    $this->actingAs($tenants->a->owner)->withSession(['tenancy.organization_id' => $tenants->a->organization->id, 'tenancy.property_id' => $tenants->a->property->id]);
    $folio = inTenant($tenants->a->property, fn () => Folio::query()->sole());
    $this->post(route('folios.payments.store', $folio), ['_property' => $tenants->a->property->id, 'method' => 'bank_transfer', 'amount' => 1000, 'idempotency_key' => 'paid'])->assertSessionHasNoErrors();
    $this->post(route('customer-accounts.close', $accounts[0]), ['_property' => $tenants->a->property->id])->assertSessionHasNoErrors();
    foreach ([$tenants->a->otherProperty, $tenants->b->property] as $property) {
        inTenant($property, function () {
            expect(Posting::query()->sole()->vfms_receipt_number)->toBeNull()->and(Posting::query()->sole()->general_receipt_id)->toBeNull()
                ->and(OutboxMessage::query()->count())->toBe(0);
        });
    }
    expect(array_map(fn (CustomerAccount $account) => $account->number, $accounts))->toBe(['ACC-000001', 'ACC-000001', 'ACC-000001']);
    $this->get(route('customer-accounts.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('accounts.data', 1));
});
