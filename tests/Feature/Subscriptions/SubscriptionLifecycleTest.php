<?php

use App\Domains\Subscriptions\Actions\AdvanceSubscription;
use App\Domains\Subscriptions\Actions\IssueSaasInvoice;
use App\Domains\Subscriptions\Actions\RecordSaasPayment;
use App\Domains\Subscriptions\Enums\SubscriptionStatus;
use App\Domains\Subscriptions\Models\SaasInvoice;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Notifications\SaasInvoiceReminder;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(now()->setDate(2026, 10, 14)->startOfHour());
});

test('the hourly lifecycle issues a trial invoice and sends each reminder only once', function () {
    [$owner, $organization] = organizationWithOwner();
    inTenant($organization, function () {
        Subscription::current()->forceFill(['trial_ends_at' => now()->addDays(3)])->save();
        app(AdvanceSubscription::class)->handle();
        app(AdvanceSubscription::class)->handle();
        expect(SaasInvoice::query()->count())->toBe(1)
            ->and(Subscription::current()->status)->toBe(SubscriptionStatus::Trial);
    });
    Notification::assertSentToTimes($owner, SaasInvoiceReminder::class, 1);
    Notification::assertSentTo($owner, SaasInvoiceReminder::class, fn ($notification) => ! $notification->overdue);

    $this->travel(3)->days();
    inTenant($organization, function () {
        app(AdvanceSubscription::class)->handle();
        app(AdvanceSubscription::class)->handle();
        expect(Subscription::current()->status)->toBe(SubscriptionStatus::PastDue)
            ->and(Subscription::current()->stateChanges()->where('to', SubscriptionStatus::PastDue)->count())->toBe(1);
    });
    Notification::assertSentToTimes($owner, SaasInvoiceReminder::class, 2);
    Notification::assertSentTo($owner, SaasInvoiceReminder::class, fn ($notification) => $notification->overdue);
});

test('an unpaid subscription suspends at the grace boundary and each transition has a reason', function () {
    [, $organization] = organizationWithOwner();
    $deadline = now()->addDays(2);
    inTenant($organization, function () {
        $subscription = Subscription::current();
        $subscription->plan->forceFill(['grace_days' => 2])->save();
        $subscription->forceFill(['trial_ends_at' => now()])->save();
        app(AdvanceSubscription::class)->handle();
        expect(Subscription::current()->status)->toBe(SubscriptionStatus::PastDue);
    });
    $this->travelTo($deadline->subSecond());
    inTenant($organization, function () {
        app(AdvanceSubscription::class)->handle();
        expect(Subscription::current()->status)->toBe(SubscriptionStatus::PastDue);
    });
    $this->travel(1)->seconds();
    inTenant($organization, function () {
        app(AdvanceSubscription::class)->handle();
        app(AdvanceSubscription::class)->handle();
        $subscription = Subscription::current();
        expect($subscription->status)->toBe(SubscriptionStatus::Suspended)
            ->and($subscription->stateChanges()->where('to', SubscriptionStatus::Suspended)->count())->toBe(1)
            ->and($subscription->stateChanges()->where('reason', '')->count())->toBe(0)
            ->and(Activity::query()->where('event', 'subscription_status_changed')->count())->toBe(2);
    });
});

test('a settled trial remains active and renews without issuing duplicate invoices', function () {
    [, $organization] = organizationWithOwner();
    $admin = platformStaff();
    $periodEnd = inTenant($organization, function () use ($admin) {
        $subscription = Subscription::current();
        $subscription->forceFill(['trial_ends_at' => now()->addDays(3)])->save();
        $invoice = app(IssueSaasInvoice::class)->handle($subscription);
        app(RecordSaasPayment::class)->handle($admin, $invoice, [
            'amount' => $invoice->amount, 'method' => 'bank_transfer', 'reference' => 'LIFECYCLE-PAID', 'received_on' => now()->toDateString(),
        ]);

        return $invoice->period_ends_at;
    });
    $this->travel(3)->days();
    inTenant($organization, function () {
        app(AdvanceSubscription::class)->handle();
        expect(Subscription::current()->status)->toBe(SubscriptionStatus::Active)
            ->and(SaasInvoice::query()->count())->toBe(1);
    });
    $this->travelTo($periodEnd->subDays(3));
    inTenant($organization, function () use ($periodEnd) {
        app(AdvanceSubscription::class)->handle();
        app(AdvanceSubscription::class)->handle();
        expect(SaasInvoice::query()->count())->toBe(2)
            ->and(SaasInvoice::query()->orderByDesc('period_starts_at')->first()->period_starts_at->equalTo($periodEnd))->toBeTrue()
            ->and(Subscription::current()->status)->toBe(SubscriptionStatus::Active);
    });
});

test('a cancelled subscription ends at its period boundary without a renewal', function () {
    [, $organization] = organizationWithOwner();
    inTenant($organization, function () {
        Subscription::current()->forceFill([
            'status' => SubscriptionStatus::Active, 'cancel_at_period_end' => true,
            'current_period_ends_at' => now()->addDay(),
        ])->save();
        app(AdvanceSubscription::class)->handle();
        expect(Subscription::current()->status)->toBe(SubscriptionStatus::Active)
            ->and(SaasInvoice::query()->count())->toBe(0);
    });
    $this->travel(1)->days();
    inTenant($organization, function () {
        app(AdvanceSubscription::class)->handle();
        app(AdvanceSubscription::class)->handle();
        expect(Subscription::current()->status)->toBe(SubscriptionStatus::Cancelled)
            ->and(SaasInvoice::query()->count())->toBe(0)
            ->and(Subscription::current()->stateChanges()->where('to', SubscriptionStatus::Cancelled)->count())->toBe(1);
    });
});

test('the hourly command advances each organization and notifies only its own owners', function () {
    [$ownerA, $organizationA] = organizationWithOwner();
    [$ownerB, $organizationB] = organizationWithOwner();
    inTenant($organizationA, fn () => Subscription::current()->forceFill(['trial_ends_at' => now()])->save());

    $this->artisan('subscriptions:advance')->assertSuccessful();
    $this->artisan('subscriptions:advance')->assertSuccessful();

    expect(inTenant($organizationA, fn () => Subscription::current()->status))->toBe(SubscriptionStatus::PastDue)
        ->and(inTenant($organizationB, fn () => Subscription::current()->status))->toBe(SubscriptionStatus::Trial)
        ->and(inTenant($organizationA, fn () => SaasInvoice::query()->count()))->toBe(1)
        ->and(inTenant($organizationB, fn () => SaasInvoice::query()->count()))->toBe(0)
        ->and(Tenancy::current())->toBeNull();
    Notification::assertSentTo($ownerA, SaasInvoiceReminder::class, fn ($notification) => $notification->invoice->organization_id === $organizationA->id);
    Notification::assertNotSentTo($ownerB, SaasInvoiceReminder::class);
});

test('missed billing windows issue every unpaid period once and send only overdue reminders', function () {
    [$owner, $organization] = organizationWithOwner();
    inTenant($organization, function () {
        Subscription::current()->forceFill(['trial_ends_at' => now()->subMonths(3)])->save();
        app(AdvanceSubscription::class)->handle();
        expect(SaasInvoice::query()->count())->toBe(4)
            ->and(Subscription::current()->status)->toBe(SubscriptionStatus::Suspended);
    });
    $this->travel(1)->hours();
    inTenant($organization, function () {
        app(AdvanceSubscription::class)->handle();
        app(AdvanceSubscription::class)->handle();
        expect(SaasInvoice::query()->count())->toBe(4)
            ->and(SaasInvoice::query()->orderBy('period_starts_at')->get()->map(fn (SaasInvoice $invoice) => $invoice->period_starts_at->toDateString())->all())
            ->toBe(['2026-07-14', '2026-08-14', '2026-09-14', '2026-10-14']);
    });
    Notification::assertSentToTimes($owner, SaasInvoiceReminder::class, 4);
    Notification::assertSentTo($owner, SaasInvoiceReminder::class, fn ($notification) => $notification->overdue);
});
