<?php

namespace App\Http\Controllers\Web\Rates;

use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Actions\CreateRatePlan;
use App\Domains\Rates\Actions\DeactivateRatePlan;
use App\Domains\Rates\Actions\UpdateRatePlan;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rates\RatePlanRequest;
use App\Http\Requests\Rates\UpdateRatePlanRequest;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RatePlanController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', RatePlan::class);

        return Inertia::render('rates/index', [
            'ratePlans' => RatePlan::query()->with(['roomTypes', 'basePlan'])->orderBy('code')->get()->map(fn (RatePlan $plan) => [
                'id' => $plan->id, 'name' => $plan->name, 'code' => $plan->code, 'currency' => $plan->currency->value, 'active' => $plan->deactivated_at === null,
                'roomTypes' => $plan->roomTypes->map->only(['id', 'name', 'code']),
                'base' => $plan->basePlan === null ? null : ['id' => $plan->basePlan->id, 'name' => $plan->basePlan->name, 'adjustmentKind' => $plan->adjustment_kind?->value, 'adjustment' => $plan->adjustment],
            ]),
            'canManage' => Gate::allows('create', RatePlan::class),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', RatePlan::class);

        return Inertia::render('rates/create', [
            'roomTypes' => RoomType::query()->orderBy('code')->get(['id', 'name', 'code']),
            'currencies' => JurisdictionRules::allowedCurrencies(Tenancy::property()),
            'basePlans' => RatePlan::query()->bases()->orderBy('code')->get()->map(fn (RatePlan $plan) => ['id' => $plan->id, 'name' => $plan->name, 'code' => $plan->code, 'currency' => $plan->currency->value]),
        ]);
    }

    public function store(RatePlanRequest $request, CreateRatePlan $create): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        /** @var array{name: string, code: string, currency?: string, room_type_ids?: list<string>, base_rate_plan_id?: string, adjustment_kind?: string, adjustment?: int|string, cancellation_free_days?: int|null, cancellation_charge?: string|null, cancellation_percent?: int|null, no_show_charge?: string|null, no_show_percent?: int|null} $details */
        $details = $request->validated();
        if (isset($details['adjustment'])) {
            $details['adjustment'] = (int) $details['adjustment'];
        }
        $plan = $create->handle($actor, $details);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rate plan added.')]);

        return redirect()->route('rate-plans.prices', $plan);
    }

    public function edit(RatePlan $ratePlan): Response
    {
        Gate::authorize('update', $ratePlan);

        return Inertia::render('rates/edit', [
            'plan' => [...$ratePlan->only(['id', 'name', 'code']), 'currency' => $ratePlan->currency->value, 'room_type_ids' => $ratePlan->roomTypes()->pluck('room_types.id')->all(), 'baseName' => $ratePlan->basePlan?->name,
                ...$ratePlan->only(['cancellation_free_days', 'cancellation_percent', 'no_show_percent']),
                'cancellation_charge' => $ratePlan->cancellation_charge?->value, 'no_show_charge' => $ratePlan->no_show_charge?->value],
            'roomTypes' => RoomType::query()->orderBy('code')->get(['id', 'name', 'code']),
        ]);
    }

    public function update(UpdateRatePlanRequest $request, RatePlan $ratePlan, UpdateRatePlan $update): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        /** @var array{name: string, code: string, room_type_ids?: list<string>, cancellation_free_days?: int|null, cancellation_charge?: string|null, cancellation_percent?: int|null, no_show_charge?: string|null, no_show_percent?: int|null} $details */
        $details = $request->validated();
        $update->handle($actor, $ratePlan, $details);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rate plan updated.')]);

        return redirect()->route('rate-plans.index');
    }

    public function deactivate(Request $request, RatePlan $ratePlan, DeactivateRatePlan $deactivate): RedirectResponse
    {
        Gate::authorize('update', $ratePlan);
        /** @var User $actor */
        $actor = $request->user();
        $deactivate->handle($actor, $ratePlan);
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':plan deactivated.', ['plan' => $ratePlan->name])]);

        return redirect()->route('rate-plans.index');
    }
}
