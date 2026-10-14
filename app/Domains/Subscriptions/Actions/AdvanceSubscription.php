<?php

namespace App\Domains\Subscriptions\Actions;

use App\Domains\Subscriptions\Enums\SaasInvoiceStatus;
use App\Domains\Subscriptions\Enums\SubscriptionStatus;
use App\Domains\Subscriptions\Models\SaasInvoice;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Notifications\SaasInvoiceReminder;
use App\Domains\Tenancy\Models\OrganizationMembership;
use App\Support\Tenancy\Access;
use Illuminate\Support\Facades\DB;

class AdvanceSubscription
{
    public function handle(): void
    {
        DB::transaction(function () {
            $subscription = Subscription::query()->with('plan')->lockForUpdate()->sole();
            if ($subscription->status === SubscriptionStatus::Cancelled || $subscription->getAttribute('last_advanced_at')?->greaterThanOrEqualTo(now()->startOfHour())) {
                return;
            }
            $subscription->forceFill(['last_advanced_at' => now()])->save();

            $periodEnds = $subscription->current_period_ends_at ?? $subscription->trial_ends_at;
            if ($subscription->cancel_at_period_end && $periodEnds?->lessThanOrEqualTo(now())) {
                app(TransitionSubscription::class)->handle($subscription, SubscriptionStatus::Cancelled, __('Cancellation took effect at the end of the subscription period.'));

                return;
            }

            if (! $subscription->cancel_at_period_end) {
                while ($subscription->nextPeriodStartsAt()->lessThanOrEqualTo(now()->addDays(3))) {
                    app(IssueSaasInvoice::class)->handle($subscription);
                }
            }

            $open = SaasInvoice::query()->where('subscription_id', $subscription->id)
                ->where('status', SaasInvoiceStatus::Open)->orderBy('due_at')->lockForUpdate()->get();
            $overdue = $open->first(fn (SaasInvoice $invoice) => $invoice->due_at->lessThanOrEqualTo(now()));
            if ($overdue !== null) {
                if (in_array($subscription->status, [SubscriptionStatus::Trial, SubscriptionStatus::Active], true)) {
                    $subscription = app(TransitionSubscription::class)->handle($subscription, SubscriptionStatus::PastDue,
                        __('Invoice :number reached its due date without full payment.', ['number' => $overdue->number]));
                }
                if ($subscription->status === SubscriptionStatus::PastDue && $overdue->due_at->addDays($subscription->plan->grace_days)->lessThanOrEqualTo(now())) {
                    app(TransitionSubscription::class)->handle($subscription, SubscriptionStatus::Suspended,
                        __('The grace period for unpaid invoice :number ended.', ['number' => $overdue->number]));
                }
            }

            foreach ($open as $invoice) {
                $overdue = $invoice->due_at->lessThanOrEqualTo(now());
                $column = $overdue ? 'after_reminded_at' : 'before_reminded_at';
                if ($invoice->getAttribute($column) !== null || (! $overdue && $invoice->due_at->greaterThan(now()->addDays(3)))) {
                    continue;
                }
                foreach (OrganizationMembership::query()->with('user')->get() as $membership) {
                    if (Access::isOwner($membership->user)) {
                        $membership->user->notify(new SaasInvoiceReminder($invoice, $overdue));
                    }
                }
                $invoice->forceFill([$column => now()])->save();
            }
        });
    }
}
