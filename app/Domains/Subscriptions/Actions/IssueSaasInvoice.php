<?php

namespace App\Domains\Subscriptions\Actions;

use App\Domains\Subscriptions\Enums\SaasInvoiceStatus;
use App\Domains\Subscriptions\Models\SaasInvoice;
use App\Domains\Subscriptions\Models\Subscription;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Issues a subscription's next SaaS invoice, for the billing period after the
 * last one invoiced (or after the trial), at the plan's current price. Billed in
 * advance: due when its period starts. Runs in the subscription's organization.
 * One invoice per period: a second call for the same period is refused by the
 * unique (subscription, period start) index.
 */
class IssueSaasInvoice
{
    public function handle(Subscription $subscription, ?User $actor = null): SaasInvoice
    {
        return DB::transaction(function () use ($subscription, $actor) {
            $subscription = Subscription::query()->with('plan')->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $plan = $subscription->plan;
            $starts = SaasInvoice::query()->where('subscription_id', $subscription->id)->max('period_ends_at');
            $starts = $starts !== null ? now()->parse($starts)->toImmutable()
                : ($subscription->current_period_ends_at ?? $subscription->trial_ends_at ?? now()->toImmutable());
            // Numbered across the platform, apart from every property's receipt sequence, so it reads past
            // the tenant scope. ponytail: locked max()+1, with the unique index as the backstop; move to a
            // sequence row if issuing ever runs concurrently at volume.
            $sequence = (int) DB::table('saas_invoices')->lockForUpdate()->max('sequence') + 1;

            $invoice = SaasInvoice::query()->create([
                'subscription_id' => $subscription->id,
                'sequence' => $sequence,
                'number' => SaasInvoice::PREFIX.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
                'period_starts_at' => $starts,
                'period_ends_at' => $starts->addMonthsNoOverflow($plan->billing_period->months()),
                'due_at' => $starts,
                'amount' => $plan->price,
                'currency' => $plan->currency,
                'status' => SaasInvoiceStatus::Open,
            ]);
            Audit::record($actor, 'saas_invoice_issued', $invoice, 'SaaS invoice issued', ['attributes' => [
                'number' => $invoice->number, 'amount' => $invoice->amount, 'currency' => $invoice->currency->value, 'due_at' => $invoice->due_at->toIso8601String(),
            ]]);

            return $invoice;
        });
    }
}
