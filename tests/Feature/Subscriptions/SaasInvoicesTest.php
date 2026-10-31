<?php

use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Billing\Models\Receipt;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Subscriptions\Actions\ChangeSubscribedRooms;
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
use Tests\Support\BookingForm;

beforeEach(fn () => withBillingDetails());

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
        ->rooms->toBe(150)
        ->unit_price->toBe($subscription->plan->price)
        ->amount->toBe(150 * $subscription->plan->price)
        ->currency->toBe($subscription->plan->currency)
        ->number->toBe('SAAS-000001')
        ->and($first->period_starts_at->toIso8601String())->toBe($subscription->trial_ends_at->toIso8601String())
        ->and($first->period_ends_at->toIso8601String())->toBe($subscription->trial_ends_at->addMonth()->toIso8601String())
        ->and($first->due_at->toIso8601String())->toBe($subscription->trial_ends_at->toIso8601String())
        ->and($second->number)->toBe('SAAS-000002')
        ->and($next->number)->toBe('SAAS-000003')
        ->and($next->period_starts_at->toIso8601String())->toBe($first->period_ends_at->toIso8601String());
});

test('a change of subscribed rooms prices the next invoice, never one already issued', function () {
    [$owner, $organization] = organizationWithOwner();
    $first = saasInvoiceFor($organization);
    inTenant($organization, fn () => app(ChangeSubscribedRooms::class)->handle($owner, 3));

    $next = saasInvoiceFor($organization);

    expect($first->fresh())->rooms->toBe(150)->amount->toBe(150 * 4900)
        ->and($next)->rooms->toBe(3)->unit_price->toBe(4900)->amount->toBe(14700);
});

test('a platform administrator issues the next invoice for an organization', function () {
    [, $organization] = organizationWithOwner();

    $this->actingAs(platformStaff())->post(route('platform.invoices.store'), ['organization_id' => $organization->id])
        ->assertRedirect(route('platform.invoices.index'));

    expect(inTenant($organization, fn () => SaasInvoice::query()->count()))->toBe(1);
});

test('a platform invoice issued on day three of a trial starts its paid month immediately', function () {
    $this->travelTo(now()->setDate(2026, 10, 1)->startOfDay());
    [, $organization] = organizationWithOwner();
    inTenant($organization, fn () => Subscription::current()->update(['trial_ends_at' => now()->addDays(14)]));
    $this->travelTo(now()->setDate(2026, 10, 3));

    $this->actingAs(platformStaff())->post(route('platform.invoices.store'), ['organization_id' => $organization->id])
        ->assertRedirect(route('platform.invoices.index'))->assertSessionHasNoErrors();

    $invoice = inTenant($organization, fn () => SaasInvoice::query()->sole());
    expect($invoice->period_starts_at->toDateString())->toBe('2026-10-03')
        ->and($invoice->period_ends_at->toDateString())->toBe('2026-11-03')
        ->and($invoice->due_at->toDateString())->toBe('2026-10-03')
        ->and(inTenant($organization, fn () => Subscription::current()->status))->toBe(SubscriptionStatus::Trial);
});

test('a later platform invoice follows the first invoiced period even while still on trial', function () {
    $this->travelTo(now()->setDate(2026, 10, 3)->startOfDay());
    [, $organization] = organizationWithOwner();
    $this->actingAs(platformStaff());
    $this->post(route('platform.invoices.store'), ['organization_id' => $organization->id])
        ->assertSessionHasNoErrors();

    $this->travel(1)->days();
    $this->post(route('platform.invoices.store'), ['organization_id' => $organization->id])
        ->assertRedirect(route('platform.invoices.index'))->assertSessionHasNoErrors();

    $invoice = inTenant($organization, fn () => SaasInvoice::query()->orderByDesc('sequence')->firstOrFail());
    expect($invoice->period_starts_at->toDateString())->toBe('2026-11-03')
        ->and($invoice->period_ends_at->toDateString())->toBe('2026-12-03')
        ->and($invoice->due_at->toDateString())->toBe('2026-11-03')
        ->and(inTenant($organization, fn () => Subscription::current()->status))->toBe(SubscriptionStatus::Trial);
});

test('paying the first platform trial invoice activates its paid month and the hourly job renews that period', function () {
    $this->travelTo(now()->setDate(2026, 10, 1)->startOfDay());
    [$owner, $organization] = organizationWithOwner();
    inTenant($organization, fn () => Subscription::current()->update(['trial_ends_at' => now()->addDays(14)]));
    $this->travelTo(now()->setDate(2026, 10, 3));
    $this->actingAs(platformStaff())->post(route('platform.invoices.store'), ['organization_id' => $organization->id])
        ->assertSessionHasNoErrors();
    $invoice = inTenant($organization, fn () => SaasInvoice::query()->sole());

    $this->post(route('platform.invoices.payments.store', $invoice->id), saasPaymentPayload([
        'amount' => $invoice->amount, 'received_on' => '2026-10-03',
    ]))->assertRedirect(route('platform.invoices.index'))->assertSessionHasNoErrors();

    expect($invoice->fresh()->status)->toBe(SaasInvoiceStatus::Paid);
    $this->actingAs($owner)->get(route('subscription.show'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('subscription.status', 'active')
            ->where('subscription.currentPeriodEndsAt', '2026-11-03T00:00:00+00:00'));

    $this->travelTo(now()->setDate(2026, 10, 15));
    $this->artisan('subscriptions:advance')->assertSuccessful();
    expect(inTenant($organization, fn () => SaasInvoice::query()->count()))->toBe(1);

    $this->travelTo(now()->setDate(2026, 10, 31));
    $this->artisan('subscriptions:advance')->assertSuccessful();
    $this->artisan('subscriptions:advance')->assertSuccessful();
    $renewal = inTenant($organization, fn () => SaasInvoice::query()->orderByDesc('sequence')->firstOrFail());
    expect(inTenant($organization, fn () => SaasInvoice::query()->count()))->toBe(2)
        ->and($renewal->period_starts_at->toDateString())->toBe('2026-11-03')
        ->and($renewal->period_ends_at->toDateString())->toBe('2026-12-03')
        ->and($renewal->due_at->toDateString())->toBe('2026-11-03')
        ->and(inTenant($organization, fn () => Subscription::current()->status))->toBe(SubscriptionStatus::Active);
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

test('settling a due invoice activates the subscription even when a future invoice remains open', function (SubscriptionStatus $status) {
    $this->freezeTime();
    [, $organization] = organizationWithOwner();
    inTenant($organization, fn () => Subscription::current()->forceFill(['status' => $status, 'trial_ends_at' => now()])->save());
    $due = saasInvoiceFor($organization);
    $future = saasInvoiceFor($organization);

    $this->actingAs(platformStaff())->post(route('platform.invoices.payments.store', $due->id),
        saasPaymentPayload(['amount' => $due->amount]))->assertSessionHasNoErrors();

    expect($due->fresh()->status)->toBe(SaasInvoiceStatus::Paid)
        ->and($future->fresh()->status)->toBe(SaasInvoiceStatus::Open)
        ->and(inTenant($organization, fn () => Subscription::current()->status))->toBe(SubscriptionStatus::Active);
})->with([SubscriptionStatus::Trial, SubscriptionStatus::PastDue, SubscriptionStatus::Suspended]);

test('payments are validated: a reference is required and no more than the balance is taken', function () {
    [, $organization] = organizationWithOwner();
    $invoice = saasInvoiceFor($organization);

    $this->actingAs(platformStaff())
        ->post(route('platform.invoices.payments.store', $invoice->id), saasPaymentPayload(['reference' => '', 'method' => 'cash']))
        ->assertSessionHasErrors(['reference', 'method']);
    $this->actingAs(platformStaff())
        ->post(route('platform.invoices.payments.store', $invoice->id), saasPaymentPayload(['amount' => $invoice->amount + 1]))
        ->assertSessionHasErrors('amount');

    expect(inTenant($organization, fn () => SaasPayment::query()->count()))->toBe(0)
        ->and(Activity::query()->where('event', 'refused')->where('subject_id', $invoice->id)
            ->where('properties->result', 'refused')->exists())->toBeTrue();
});

test('paying invoices out of order activates through the latest paid period', function () {
    $this->freezeTime();
    [, $organization] = organizationWithOwner();
    inTenant($organization, fn () => Subscription::current()->update(['trial_ends_at' => now()]));
    $first = saasInvoiceFor($organization);
    $second = saasInvoiceFor($organization);
    $this->actingAs(platformStaff());

    $this->post(route('platform.invoices.payments.store', $second->id), saasPaymentPayload(['amount' => $second->amount]))
        ->assertSessionHasNoErrors();
    expect(inTenant($organization, fn () => Subscription::current()->status))->toBe(SubscriptionStatus::Trial);

    $this->post(route('platform.invoices.payments.store', $first->id), saasPaymentPayload(['amount' => $first->amount]))
        ->assertSessionHasNoErrors();
    $subscription = inTenant($organization, fn () => Subscription::current());
    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->current_period_ends_at->toIso8601String())->toBe($second->period_ends_at->toIso8601String());
});

test('zero-priced invoices settle without inventing a payment and activate the subscription', function () {
    [, $organization] = organizationWithOwner();
    inTenant($organization, fn () => Subscription::current()->plan->update(['price' => 0]));

    $invoice = saasInvoiceFor($organization);
    $subscription = inTenant($organization, fn () => Subscription::current());
    expect($invoice->status)->toBe(SaasInvoiceStatus::Paid)
        ->and($invoice->paid_at)->not->toBeNull()
        ->and($invoice->amount)->toBe(0)
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->current_period_ends_at->toIso8601String())->toBe($invoice->period_ends_at->toIso8601String())
        ->and(inTenant($organization, fn () => SaasPayment::query()->count()))->toBe(0)
        ->and(inTenant($organization, fn () => $subscription->stateChanges()->first()->reason))->toContain($invoice->number);
});

test('a settled invoice refuses another payment and keeps the refusal audit', function () {
    [, $organization] = organizationWithOwner();
    $invoice = saasInvoiceFor($organization);
    $this->actingAs(platformStaff());
    $this->post(route('platform.invoices.payments.store', $invoice->id), saasPaymentPayload(['amount' => $invoice->amount]))
        ->assertSessionHasNoErrors();
    $this->post(route('platform.invoices.payments.store', $invoice->id), saasPaymentPayload(['amount' => 1]))
        ->assertSessionHasErrors('amount');

    expect(inTenant($organization, fn () => SaasPayment::query()->count()))->toBe(1)
        ->and(Activity::query()->where('event', 'refused')->where('subject_id', $invoice->id)->exists())->toBeTrue();
});

test('settling a cancelled subscription invoice does not reactivate it', function () {
    [, $organization] = organizationWithOwner();
    $invoice = saasInvoiceFor($organization);
    inTenant($organization, fn () => app(TransitionSubscription::class)
        ->handle(Subscription::current(), SubscriptionStatus::Cancelled, 'Cancelled by owner'));

    $this->actingAs(platformStaff())->post(route('platform.invoices.payments.store', $invoice->id), saasPaymentPayload(['amount' => $invoice->amount]))
        ->assertSessionHasNoErrors();

    expect($invoice->fresh()->status)->toBe(SaasInvoiceStatus::Paid)
        ->and(inTenant($organization, fn () => Subscription::current()->status))->toBe(SubscriptionStatus::Cancelled);
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

test('a folio payment leaves the SaaS invoice outstanding and its subscription in trial', function () {
    [$owner, $organization, $property] = organizationWithOwner();
    $invoice = saasInvoiceFor($organization);

    inTenant($property, function () use ($owner, $invoice) {
        $reservation = app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
        $folio = Folio::query()->where('reservation_id', $reservation->id)->sole();
        $posting = app(RecordPayment::class)->handle($owner, $folio, [
            'method' => 'bank_transfer', 'amount' => $invoice->amount, 'currency' => $folio->currency->value, 'reference' => 'GUEST-TRANSFER',
        ]);

        expect($posting->receipt?->number)->toBe('RCT-000001')
            ->and($invoice->fresh()->status)->toBe(SaasInvoiceStatus::Open)
            ->and($invoice->balance())->toBe($invoice->amount)
            ->and(SaasPayment::query()->count())->toBe(0)
            ->and(Subscription::current()->status)->toBe(SubscriptionStatus::Trial);
    });
});
