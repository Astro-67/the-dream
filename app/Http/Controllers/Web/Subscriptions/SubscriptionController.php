<?php

namespace App\Http\Controllers\Web\Subscriptions;

use App\Domains\Subscriptions\Actions\ChangeSubscribedRooms;
use App\Domains\Subscriptions\Models\SaasInvoice;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionStateChange;
use App\Domains\Subscriptions\PlanUsage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Subscriptions\SubscribedRoomsRequest;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The owner's view of their organization's subscription: plan, limits, usage and state.
 */
class SubscriptionController extends Controller
{
    public function show(): Response
    {
        Gate::authorize('view', Subscription::class);
        $subscription = Subscription::current();
        $plan = $subscription->plan;

        return Inertia::render('subscription/show', [
            'subscription' => [
                'status' => $subscription->status->value,
                'statusLabel' => $subscription->status->label(),
                'trialEndsAt' => $subscription->trial_ends_at?->toIso8601String(),
                'currentPeriodEndsAt' => $subscription->current_period_ends_at?->toIso8601String(),
                'rooms' => $subscription->rooms,
            ],
            'plan' => [
                'name' => $plan->name, 'propertyLimit' => $plan->property_limit,
                'price' => $plan->price, 'currency' => $plan->currency->value, 'billingPeriod' => $plan->billing_period->label(),
            ],
            'usage' => PlanUsage::current(),
            'invoices' => SaasInvoice::query()->withSum('payments', 'amount')->orderByDesc('sequence')->paginate(50)->withQueryString()
                ->through(fn (SaasInvoice $invoice) => ['id' => $invoice->id, ...$invoice->summary()]),
            'history' => $subscription->stateChanges()->with('actor')->limit(50)->get()->map(fn (SubscriptionStateChange $change) => [
                'id' => $change->id, 'from' => $change->from?->label(), 'to' => $change->to->label(),
                'reason' => $change->reason, 'actor' => $change->actor?->name, 'at' => $change->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function updateRooms(SubscribedRoomsRequest $request, ChangeSubscribedRooms $change): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $change->handle($actor, (int) $request->validated('rooms'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Subscribed rooms updated. Your next invoice uses the new number.')]);

        return to_route('subscription.show');
    }

    /** A platform administrator setting an organization's subscribed rooms; runs in that organization. */
    public function platformUpdateRooms(SubscribedRoomsRequest $request, string $organization, ChangeSubscribedRooms $change): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $rooms = (int) $request->validated('rooms');
        Tenancy::within(TenantContext::forOrganization($organization), fn () => $change->handle($actor, $rooms));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Subscribed rooms updated.')]);

        return to_route('platform.organizations.show', $organization);
    }

    /** The owner's printable SaaS invoice; route binding is scoped to their organization. */
    public function invoice(SaasInvoice $invoice): Response
    {
        Gate::authorize('view', Subscription::class);

        return Inertia::render('subscription/invoice', $invoice->view(forOwner: true));
    }
}
