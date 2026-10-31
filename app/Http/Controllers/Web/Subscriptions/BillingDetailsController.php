<?php

namespace App\Http\Controllers\Web\Subscriptions;

use App\Domains\Subscriptions\Actions\SaveBillingDetails;
use App\Domains\Subscriptions\Models\BillingDetails;
use App\Http\Controllers\Controller;
use App\Http\Requests\Subscriptions\BillingDetailsRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Thedream's billing details printed on new SaaS invoices. Platform page: no tenant context. */
class BillingDetailsController extends Controller
{
    public function edit(): Response
    {
        Gate::authorize('manage', BillingDetails::class);

        return Inertia::render('platform/billing-details/edit', ['details' => BillingDetails::current()?->only(BillingDetails::FIELDS)]);
    }

    public function update(BillingDetailsRequest $request, SaveBillingDetails $save): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $save->handle($actor, $request->details());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Billing details saved.')]);

        return to_route('platform.billing-details.edit');
    }
}
