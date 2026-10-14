<?php

namespace App\Domains\Subscriptions\Actions;

use App\Domains\Subscriptions\Enums\SaasInvoiceStatus;
use App\Domains\Subscriptions\Models\SaasInvoice;
use App\Domains\Subscriptions\Models\SaasPayment;
use App\Domains\Subscriptions\Models\Subscription;
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
        return Audit::validationRefusal($actor, $invoice, 'SaaS payment refused', fn () => DB::transaction(function () use ($actor, $invoice, $details) {
            Subscription::query()->whereKey($invoice->subscription_id)->lockForUpdate()->firstOrFail();
            $invoice = SaasInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($invoice->status === SaasInvoiceStatus::Paid) {
                throw ValidationException::withMessages(['amount' => __('This invoice is already paid.')]);
            }
            if ($details['amount'] > $invoice->balance()) {
                throw ValidationException::withMessages(['amount' => __('The payment is more than the :amount still owed.', ['amount' => $invoice->balance()])]);
            }

            $payment = $invoice->payments()->create([...$details, 'recorded_by' => $actor->id]);
            Audit::record($actor, 'saas_payment_recorded', $payment, 'SaaS payment recorded', ['attributes' => [
                'invoice' => $invoice->number, 'amount' => $payment->amount, 'method' => $payment->method->value, 'reference' => $payment->reference,
            ]]);

            if ($invoice->balance() === 0) {
                app(SettleSaasInvoice::class)->handle($invoice, $actor);
            }

            return $payment;
        }));
    }
}
