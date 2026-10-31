<?php

use App\Domains\Platform\Enums\PlatformRole;
use App\Domains\Subscriptions\Actions\AdvanceSubscription;
use App\Domains\Subscriptions\Actions\IssueSaasInvoice;
use App\Domains\Subscriptions\Models\BillingDetails;
use App\Domains\Subscriptions\Models\SaasInvoice;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

test('issuing an invoice by hand is refused and audited until billing details and an account exist', function () {
    [, $organization] = organizationWithOwner();

    $this->actingAs(platformStaff())->post(route('platform.invoices.store'), ['organization_id' => $organization->id])
        ->assertSessionHasErrors('organization_id');

    expect(inTenant($organization, fn () => SaasInvoice::query()->count()))->toBe(0)
        ->and(Activity::query()->where('event', 'refused')->where('properties->result', 'refused')->exists())->toBeTrue();
});

test('the hourly renewal skips issuing, with an audited reason, while no payment account exists, and still advances', function () {
    withBillingDetails(['accounts' => []]);
    [, $organization] = organizationWithOwner();

    inTenant($organization, function () {
        Subscription::current()->forceFill(['trial_ends_at' => now()->addDay()])->save();
        app(AdvanceSubscription::class)->handle();

        expect(SaasInvoice::query()->count())->toBe(0)
            ->and(Subscription::current()->last_advanced_at)->not->toBeNull();
    });
    expect(Activity::query()->where('event', 'refused')->where('description', 'SaaS invoice refused')->sole()->properties['errors'])
        ->toHaveKey('organization_id');
});

/** @param array<string, mixed> $overrides */
function billingDetailsPayload(array $overrides = []): array
{
    return [
        'business_name' => 'Thedream Ltd', 'address' => 'Mlandege Street, Zanzibar', 'phone' => '+255 777 000 111',
        'email' => 'billing@thedream.test', 'tin' => '100-200-300', 'vrn' => '',
        'accounts' => [
            ['type' => 'bank', 'bank' => 'CRDB Bank', 'branch' => 'Stone Town', 'swift' => '', 'account_name' => 'Thedream Ltd', 'account_number' => '0150123456700'],
            ['type' => 'mobile_money', 'provider' => 'M-Pesa', 'account_name' => 'Thedream Ltd', 'account_number' => '0777000111'],
        ],
        ...$overrides,
    ];
}

test('a platform administrator saves the billing details, and a new invoice keeps its snapshot after they change', function () {
    $admin = platformStaff();
    [, $organization] = organizationWithOwner();

    $this->actingAs($admin)->put(route('platform.billing-details.update'), billingDetailsPayload())
        ->assertRedirect(route('platform.billing-details.edit'))->assertSessionHasNoErrors();
    $this->post(route('platform.invoices.store'), ['organization_id' => $organization->id])->assertSessionHasNoErrors();
    $this->put(route('platform.billing-details.update'), billingDetailsPayload([
        'business_name' => 'Renamed Ltd',
        'accounts' => [['type' => 'mobile_money', 'provider' => 'Airtel Money', 'account_name' => 'Renamed Ltd', 'account_number' => '0688000111']],
    ]))->assertSessionHasNoErrors();

    $snapshot = inTenant($organization, fn () => SaasInvoice::query()->sole()->billing_snapshot);
    expect($snapshot['business_name'])->toBe('Thedream Ltd')
        ->and($snapshot['organization_name'])->toBe($organization->name)
        ->and($snapshot['vrn'])->toBeNull()
        ->and($snapshot['accounts'])->toEqual([
            ['type' => 'bank', 'bank' => 'CRDB Bank', 'branch' => 'Stone Town', 'swift' => null, 'account_name' => 'Thedream Ltd', 'account_number' => '0150123456700'],
            ['type' => 'mobile_money', 'provider' => 'M-Pesa', 'account_name' => 'Thedream Ltd', 'account_number' => '0777000111'],
        ])
        ->and(Activity::query()->where('event', 'billing_details_updated')->count())->toBe(2);

    $this->get(route('platform.billing-details.edit'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('platform/billing-details/edit')
            ->where('details.business_name', 'Renamed Ltd')->has('details.accounts', 1));
});

test('billing details are validated per account type', function () {
    $this->actingAs(platformStaff())->put(route('platform.billing-details.update'), billingDetailsPayload([
        'email' => 'not-an-email',
        'accounts' => [['type' => 'bank', 'account_name' => 'Thedream Ltd', 'account_number' => '1'], ['type' => 'cash']],
    ]))->assertSessionHasErrors(['email', 'accounts.0.bank', 'accounts.1.type']);

    expect(Activity::query()->where('event', 'refused')->where('properties->action', 'platform.billing-details.update')->exists())->toBeTrue();

    expect(BillingDetails::current())->toBeNull();
});

test('organization users, owners included, cannot see or change the billing details', function () {
    [$owner] = organizationWithOwner();

    $this->actingAs($owner)->get(route('platform.billing-details.edit'))->assertForbidden();
    $this->actingAs($owner)->put(route('platform.billing-details.update'), billingDetailsPayload())->assertForbidden();
    $this->actingAs(platformStaff(PlatformRole::ComplianceOperator))->put(route('platform.billing-details.update'), billingDetailsPayload())->assertForbidden();

    expect(BillingDetails::current())->toBeNull();
});

test('owners and platform administrators open the invoice from its snapshot; other organizations cannot', function () {
    withBillingDetails();
    [$owner, $organization] = organizationWithOwner();
    [$otherOwner] = organizationWithOwner();
    $invoice = inTenant($organization, fn () => app(IssueSaasInvoice::class)->handle(Subscription::current()));
    BillingDetails::current()->update(['business_name' => 'Renamed Ltd', 'accounts' => []]);
    $page = fn (Assert $page) => $page->component('subscription/invoice')
        ->where('invoice.number', $invoice->number)
        ->where('invoice.balance', $invoice->amount)
        ->where('billing.business_name', 'Thedream Ltd')
        ->where('billing.organization_name', $organization->name)
        ->has('billing.accounts', 2)
        ->has('payments', 0);

    $this->actingAs($owner)->get(route('subscription.invoices.show', $invoice->id))->assertOk()->assertInertia($page);
    $this->actingAs(platformStaff())->get(route('platform.invoices.show', $invoice->id))->assertOk()->assertInertia($page);
    $this->actingAs($otherOwner)->get(route('subscription.invoices.show', $invoice->id))->assertNotFound();
    $this->actingAs($owner)->get(route('platform.invoices.show', $invoice->id))->assertForbidden();
});

test('an invoice issued before billing details existed opens without the details block', function () {
    withBillingDetails();
    [$owner, $organization] = organizationWithOwner();
    $invoice = inTenant($organization, fn () => app(IssueSaasInvoice::class)->handle(Subscription::current()));
    $invoice->forceFill(['billing_snapshot' => null])->save();

    $this->actingAs($owner)->get(route('subscription.invoices.show', $invoice->id))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('billing', null)->where('invoice.number', $invoice->number));
});

test('organization members without organization access cannot open their own invoice', function () {
    $t = twoTenants();
    withBillingDetails();
    $invoice = inTenant($t->a->organization, fn () => app(IssueSaasInvoice::class)->handle(Subscription::current()));

    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get(route('subscription.invoices.show', $invoice->id))->assertForbidden();
});
