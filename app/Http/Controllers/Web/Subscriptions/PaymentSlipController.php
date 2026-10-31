<?php

namespace App\Http\Controllers\Web\Subscriptions;

use App\Domains\Subscriptions\Actions\AttachPaymentSlips;
use App\Domains\Subscriptions\Models\SaasInvoice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Subscriptions\PaymentSlipsRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class PaymentSlipController extends Controller
{
    public function store(PaymentSlipsRequest $request, SaasInvoice $invoice, AttachPaymentSlips $attach): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $attach->handle($actor, $invoice, $request->validated('files'), $request->validated('reference'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment slips uploaded. Thedream will verify your payment.')]);

        return to_route('subscription.invoices.show', $invoice);
    }
}
