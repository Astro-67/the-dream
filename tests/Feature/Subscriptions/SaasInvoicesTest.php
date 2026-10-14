<?php

use App\Domains\Billing\Models\Posting;
use App\Domains\Billing\Models\Receipt;
use App\Domains\Subscriptions\Actions\IssueSaasInvoice;
use App\Domains\Subscriptions\Actions\TransitionSubscription;
use App\Domains\Subscriptions\Enums\SaasInvoiceStatus;
use App\Domains\Subscriptions\Enums\SubscriptionStatus;
use App\Domains\Subscriptions\Models\SaasInvoice;
use App\Domains\Subscriptions\Models\SaasPayment;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Tenancy\Models\Organization;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

function saasInvoiceFor(Organization $organization): SaasInvoice
{
    return inTenant($organization, fn () => app(IssueSaasInvoice::class)->handle(Subscription::current()));
}

/** @param array<string, mixed> $overrides */
function saasPaymentPayload(array $overrides = []): array
{
    return ['amount' => 4900, 'method' => 'bank_transfer', 'reference' => 'CRDB-TT-20261014-0042', 'received_on' => '2026-10-14', ...$overrides];
}

test('the first invoice covers the billing period after the trial, due when the trial ends, numbered platform-wide', function () {
    $this->freezeTime();
    [, $alpha] = organizationWithOwner();
    [, $bravo] = organizationWithOwner();
    $subscription = inTenant($alpha, fn () => Subscription::current());

    $first = saasInvoiceFor($alpha);
    $second = saasInvoiceFor($bravo);
    $next = saasInvoiceFor($alpha);

    expect($first)
        ->status->toBe(SaasInvoiceStatus::Open)
        ->amount->toBe($subscription->plan->price)
        ->currency->toBe($subscription->plan->currency)
        ->number->toBe('SAAS-000001')
        ->and($first->period_starts_at->toIso8601String())->toBe($subscription->trial_ends_at->toIso8601String())
        ->and($first->period_ends_at->toIso8601String())->toBe($subscription->trial_ends_at->addMonth()->toIso8601String())
        ->and($first->due_at->toIso8601String())->toBe($subscription->trial_ends_at->toIso8601String())
        ->and($second->number)->toBe('SAAS-000002')
        ->and($next->number)->toBe('SAAS-000003')
        ->and($next->period_starts_at->toIso8601String())->toBe($first->period_ends_at->toIso8601String());
});

test('a platform administrator issues the next invoice for an organization', function () {
    [, $organization] = organizationWithOwner();

    $this->actingAs(platformStaff())->post(route('platform.invoices.store'), ['organization_id' => $organization->id])
        ->assertRedirect(route('platform.invoices.index'));

    expect(inTenant($organization, fn () => SaasInvoice::query()->count()))->toBe(1);
});

test('a payment that settles the open invoice activates a trial subscription with a reason', function () {
    [, $organization] = organizationWithOwner();
    $invoice = saasInvoiceFor($organization);
    $admin = platformStaff();

    $this->actingAs($admin)->post(route('platform.invoices.payments.store', $invoice->id), saasPaymentPayload(['amount' => $invoice->amount]))
        ->assertRedirect(route('platform.invoices.index'))->assertSessionHasNoErrors();

    $subscription = inTenant($organization, fn () => Subscription::current());
    $payment = inTenant($organization, fn () => SaasPayment::query()->sole());
    expect($invoice->fresh())->status->toBe(SaasInvoiceStatus::Paid)->paid_at->not->toBeNull()
        ->and($payment)->reference->toBe('CRDB-TT-20261014-0042')->recorded_by->toBe($admin->id)->amount->toBe($invoice->amount)
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->current_period_ends_at->toIso8601String())->toBe($invoice->period_ends_at->toIso8601String())
        ->and(inTenant($organization, fn () => $subscription->stateChanges()->first()))
        ->to->toBe(SubscriptionStatus::Active)->actor_id->toBe($admin->id)
        ->reason->toContain($invoice->number)
        ->and(Activity::query()->where('event', 'saas_payment_recorded')->where('subject_id', $payment->id)->exists())->toBeTrue();
});

test('a past-due subscription returns to active once paid; a part payment changes nothing', function () {
    [, $organization] = organizationWithOwner();
    $invoice = saasInvoiceFor($organization);
    inTenant($organization, fn () => app(TransitionSubscription::class)
        ->handle(Subscription::current(), SubscriptionStatus::PastDue, 'Invoice overdue'));
    $admin = platformStaff();

    $this->actingAs($admin)->post(route('platform.invoices.payments.store', $invoice->id), saasPaymentPayload(['amount' => 1000, 'method' => 'mobile_money']));

    expect($invoice->fresh()->status)->toBe(SaasInvoiceStatus::Open)
        ->and(inTenant($organization, fn () => Subscription::current()->status))->toBe(SubscriptionStatus::PastDue);

    $this->actingAs($admin)->post(route('platform.invoices.payments.store', $invoice->id), saasPaymentPayload(['amount' => $invoice->amount - 1000]));

    expect($invoice->fresh()->status)->toBe(SaasInvoiceStatus::Paid)
        ->and(inTenant($organization, fn () => Subscription::current()->status))->toBe(SubscriptionStatus::Active);
});

test('payments are validated: a reference is required and no more than the balance is taken', function () {
    [, $organization] = organizationWithOwner();
    $invoice = saasInvoiceFor($organization);

    $this->actingAs(platformStaff())
        ->post(route('platform.invoices.payments.store', $invoice->id), saasPaymentPayload(['reference' => '', 'method' => 'cash']))
        ->assertSessionHasErrors(['reference', 'method']);
    $this->actingAs(platformStaff())
        ->post(route('platform.invoices.payments.store', $invoice->id), saasPaymentPayload(['amount' => $invoice->amount + 1]))
        ->assertSessionHasErrors('amount');

    expect(inTenant($organization, fn () => SaasPayment::query()->count()))->toBe(0);
});

test('organization users, owners included, cannot see invoices across organizations or record payments', function () {
    [$owner, $organization] = organizationWithOwner();
    $invoice = saasInvoiceFor($organization);

    $this->actingAs($owner)->get(route('platform.invoices.index'))->assertForbidden();
    $this->actingAs($owner)->post(route('platform.invoices.payments.store', $invoice->id), saasPaymentPayload())->assertForbidden();
    $this->actingAs($owner)->post(route('platform.invoices.store'), ['organization_id' => $organization->id])->assertForbidden();

    expect($invoice->fresh()->status)->toBe(SaasInvoiceStatus::Open)
        ->and(inTenant($organization, fn () => SaasInvoice::query()->count()))->toBe(1);
});

test('the platform invoices page lists every organization\'s invoices', function () {
    [, $alpha] = organizationWithOwner(['name' => 'Alpha Lodge']);
    [, $bravo] = organizationWithOwner(['name' => 'Bravo Inn']);
    saasInvoiceFor($alpha);
    saasInvoiceFor($bravo);

    $this->actingAs(platformStaff())->get(route('platform.invoices.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('platform/invoices/index')->has('invoices.data', 2)->has('organizations', 2));
});

test('the owner sees their own invoices on the subscription page', function () {
    [$owner, $organization] = organizationWithOwner();
    [, $other] = organizationWithOwner();
    $invoice = saasInvoiceFor($organization);
    saasInvoiceFor($other);

    $this->actingAs($owner)->get(route('subscription.show'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('invoices.data', 1)
            ->where('invoices.data.0.number', $invoice->number)
            ->where('invoices.data.0.status', 'open')
            ->where('invoices.data.0.balance', $invoice->amount));
});

test('SaaS invoices and payments never touch folios, and folio payments never touch SaaS records', function () {
    [, $organization] = organizationWithOwner();
    $invoice = saasInvoiceFor($organization);

    $this->actingAs(platformStaff())->post(route('platform.invoices.payments.store', $invoice->id), saasPaymentPayload(['amount' => $invoice->amount]));

    Tenancy::within(TenantContext::platform('Test: SaaS and folio records are separate'), function () {
        expect(Posting::query()->count())->toBe(0)->and(Receipt::query()->count())->toBe(0);
    });
    expect((new SaasInvoice)->getTable())->not->toBe((new Posting)->getTable())
        ->and($invoice->number)->toStartWith('SAAS-');
});
