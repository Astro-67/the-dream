<?php

namespace App\Domains\Subscriptions\Actions;

use App\Domains\Subscriptions\Enums\SaasInvoiceStatus;
use App\Domains\Subscriptions\Enums\SubscriptionStatus;
use App\Domains\Subscriptions\Models\SaasInvoice;
use App\Domains\Subscriptions\Models\SaasPayment;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a payment an organization made to Thedream against one of its SaaS
 * invoices. Runs in the invoice's organization. A payment that settles the last
 * open invoice moves a trial, past-due or suspended subscription to active
 * through the paid period, recorded with a reason (SUB-01). Never touches folios.
 */
class RecordSaasPayment
{
    /** @param array{amount: int, method: string, reference: string, received_on: string} $details */
    public function handle(User $actor, SaasInvoice $invoice, array $details): SaasPayment
    {
        return DB::transaction(function () use ($actor, $invoice, $details) {
            $invoice = SaasInvoice::query()->with('subscription')->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $payment = Audit::validationRefusal($actor, $invoice, 'SaaS payment refused', function () use ($actor, $invoice, $details) {
                if ($invoice->status === SaasInvoiceStatus::Paid) {
                    throw ValidationException::withMessages(['amount' => __('This invoice is already paid.')]);
                }
                if ($details['amount'] > $invoice->balance()) {
                    throw ValidationException::withMessages(['amount' => __('The payment is more than the :amount still owed.', ['amount' => $invoice->balance()])]);
                }

                return $invoice->payments()->create([...$details, 'recorded_by' => $actor->id]);
            });
            Audit::record($actor, 'saas_payment_recorded', $payment, 'SaaS payment recorded', ['attributes' => [
                'invoice' => $invoice->number, 'amount' => $payment->amount, 'method' => $payment->method->value, 'reference' => $payment->reference,
            ]]);

            if ($invoice->balance() === 0) {
                $this->settle($actor, $invoice);
            }

            return $payment;
        });
    }

    private function settle(User $actor, SaasInvoice $invoice): void
    {
        $invoice->forceFill(['status' => SaasInvoiceStatus::Paid, 'paid_at' => now()])->save();

        $subscription = $invoice->subscription;
        $stillOwed = SaasInvoice::query()->where('subscription_id', $subscription->id)->where('status', SaasInvoiceStatus::Open)->exists();
        if ($stillOwed || $subscription->status === SubscriptionStatus::Cancelled) {
            return;
        }

        $periodEnds = $subscription->current_period_ends_at !== null && $subscription->current_period_ends_at->greaterThan($invoice->period_ends_at)
            ? $subscription->current_period_ends_at : $invoice->period_ends_at;
        app(TransitionSubscription::class)->handle($subscription, SubscriptionStatus::Active,
            __('Invoice :number paid', ['number' => $invoice->number]), $actor, ['current_period_ends_at' => $periodEnds]);
    }
}
