<?php

namespace App\Domains\Subscriptions\Actions;

use App\Domains\Subscriptions\Enums\SaasInvoiceStatus;
use App\Domains\Subscriptions\Enums\SubscriptionStatus;
use App\Domains\Subscriptions\Models\SaasInvoice;
use App\Domains\Subscriptions\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

class SettleSaasInvoice
{
    public function handle(SaasInvoice $invoice, ?User $actor = null): SaasInvoice
    {
        return DB::transaction(function () use ($invoice, $actor) {
            $subscription = Subscription::query()->whereKey($invoice->subscription_id)->lockForUpdate()->firstOrFail();
            $invoice = SaasInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->balance() !== 0) {
                throw new LogicException('Only a fully paid SaaS invoice can be settled.');
            }
            if ($invoice->status === SaasInvoiceStatus::Paid) {
                return $invoice;
            }

            $invoice->forceFill(['status' => SaasInvoiceStatus::Paid, 'paid_at' => now()])->save();
            $invoices = SaasInvoice::query()->where('subscription_id', $subscription->id);
            if ((clone $invoices)->where('status', SaasInvoiceStatus::Open)->exists()
                || $subscription->status === SubscriptionStatus::Cancelled) {
                return $invoice;
            }

            $paidThrough = CarbonImmutable::parse((clone $invoices)->where('status', SaasInvoiceStatus::Paid)->max('period_ends_at'));
            $periodEnds = $subscription->current_period_ends_at?->greaterThan($paidThrough)
                ? $subscription->current_period_ends_at : $paidThrough;
            app(TransitionSubscription::class)->handle($subscription, SubscriptionStatus::Active,
                __('Invoice :number paid', ['number' => $invoice->number]), $actor, ['current_period_ends_at' => $periodEnds]);

            return $invoice;
        });
    }
}
