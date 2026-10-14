<?php

namespace App\Http\Controllers\Web\Subscriptions;

use App\Domains\Subscriptions\Actions\SavePlan;
use App\Domains\Subscriptions\Enums\BillingPeriod;
use App\Domains\Subscriptions\Models\Plan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Subscriptions\PlanRequest;
use App\Models\User;
use App\Support\Money\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform administrators' plans (SUB-02). Platform pages: no tenant context.
 */
class PlanController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('manage', Plan::class);

        return Inertia::render('platform/plans/index', [
            'plans' => Plan::query()->orderBy('name')->get()->map(fn (Plan $plan) => [
                'id' => $plan->id, ...$plan->only(Plan::FIELDS),
                'billing_period' => $plan->billing_period->value, 'billingPeriodLabel' => $plan->billing_period->label(), 'currency' => $plan->currency->value,
            ])->values(),
            'periods' => array_map(fn (BillingPeriod $period) => ['value' => $period->value, 'label' => $period->label()], BillingPeriod::cases()),
            'currencies' => array_map(fn (Currency $currency) => $currency->value, Currency::cases()),
        ]);
    }

    public function store(PlanRequest $request, SavePlan $savePlan): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $plan = $savePlan->handle($user, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added.', ['name' => $plan->name])]);

        return to_route('platform.plans.index');
    }

    public function update(PlanRequest $request, Plan $plan, SavePlan $savePlan): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $savePlan->handle($user, $request->validated(), $plan);
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name saved.', ['name' => $plan->name])]);

        return to_route('platform.plans.index');
    }
}
