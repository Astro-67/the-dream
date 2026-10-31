<?php

namespace App\Domains\Subscriptions\Actions;

use App\Domains\Subscriptions\Enums\SaasInvoiceStatus;
use App\Domains\Subscriptions\Enums\SubscriptionStatus;
use App\Domains\Subscriptions\Models\BillingDetails;
use App\Domains\Subscriptions\Models\SaasInvoice;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Tenancy\Models\Organization;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issues a subscription's next SaaS invoice, for the billing period after the
 * last one invoiced (or after the trial), at the plan's current price for each subscribed room. A platform
 * administrator's first invoice during trial starts immediately. Billed in
 * advance: due when its period starts. Runs in the subscription's organization.
 * Each call issues the next period, serialized by the subscription lock.
 * Locks subscription, platform sequence, then invoices. Billing-period reads
 * use locking reads even inside an existing caller transaction.
 * Refused, and audited, while Karafuu's billing details or every payment
 * account are missing; the invoice keeps a snapshot of them and the
 * organization's name.
 */
class IssueSaasInvoice
{
    public function handle(Subscription $subscription, ?User $actor = null): SaasInvoice
    {
        return Audit::validationRefusal($actor, $subscription, 'SaaS invoice refused', fn () => DB::transaction(function () use ($subscription, $actor) {
            $billing = BillingDetails::snapshot();
            if ($billing === null) {
                throw ValidationException::withMessages(['organization_id' => __('Add Karafuu\'s billing details and at least one payment account before issuing invoices.')]);
            }
            $subscription = Subscription::query()->with('plan')->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $plan = $subscription->plan;
            $sequence = Tenancy::within(TenantContext::platform('Allocate a platform-wide SaaS invoice number'), function () {
                $counter = DB::table('saas_invoice_sequences')->where('id', 1)->lockForUpdate()->sole();
                $next = (int) $counter->last_number + 1;
                DB::table('saas_invoice_sequences')->where('id', 1)->update(['last_number' => $next]);

                return $next;
            });
            $starts = $subscription->nextPeriodStartsAt();
            if ($actor !== null && $subscription->status === SubscriptionStatus::Trial
                && SaasInvoice::query()->where('subscription_id', $subscription->id)->lockForUpdate()->first() === null) {
                $starts = now()->toImmutable();
            }

            $invoice = SaasInvoice::query()->create([
                'subscription_id' => $subscription->id,
                'sequence' => $sequence,
                'number' => SaasInvoice::PREFIX.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
                'period_starts_at' => $starts,
                'period_ends_at' => $starts->addMonthsNoOverflow($plan->billing_period->months()),
                'due_at' => $starts,
                'rooms' => $subscription->rooms,
                'unit_price' => $plan->price,
                'amount' => $subscription->rooms * $plan->price,
                'currency' => $plan->currency,
                'status' => SaasInvoiceStatus::Open,
                'billing_snapshot' => [...$billing, 'organization_name' => Organization::query()->whereKey($subscription->organization_id)->value('name')],
            ]);
            Audit::record($actor, 'saas_invoice_issued', $invoice, 'SaaS invoice issued', ['attributes' => [
                'number' => $invoice->number, 'rooms' => $invoice->rooms, 'unit_price' => $invoice->unit_price, 'amount' => $invoice->amount, 'currency' => $invoice->currency->value, 'due_at' => $invoice->due_at->toIso8601String(),
            ]]);

            return $invoice->amount === 0
                ? app(SettleSaasInvoice::class)->handle($invoice, $actor)
                : $invoice;
        }));
    }
}
