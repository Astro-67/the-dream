<?php

namespace App\Http\Controllers\Web\Subscriptions;

use App\Domains\Subscriptions\Actions\AttachPaymentSlips;
use App\Domains\Subscriptions\Models\SaasInvoice;
use App\Domains\Subscriptions\Models\SaasPaymentSlip;
use App\Domains\Subscriptions\Models\Subscription;
use App\Http\Controllers\Controller;
use App\Http\Requests\Subscriptions\PaymentSlipsRequest;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentSlipController extends Controller
{
    public function show(SaasPaymentSlip $slip): StreamedResponse
    {
        Gate::authorize('view', Subscription::class);

        return Storage::disk('local')->download($slip->path, $slip->original_name, [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function platformShow(string $slip): StreamedResponse
    {
        Gate::authorize('manage', SaasInvoice::class);

        return Tenancy::within(TenantContext::platform('Platform administrator opened a payment slip'),
            fn () => Storage::disk('local')->download(
                ($found = SaasPaymentSlip::query()->findOrFail($slip))->path,
                $found->original_name,
                ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'],
            ));
    }

    public function store(PaymentSlipsRequest $request, SaasInvoice $invoice, AttachPaymentSlips $attach): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $attach->handle($actor, $invoice, $request->validated('files'), $request->validated('reference'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment slips uploaded. Thedream will verify your payment.')]);

        return to_route('subscription.invoices.show', $invoice);
    }
}
