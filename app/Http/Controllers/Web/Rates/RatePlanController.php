<?php

namespace App\Http\Controllers\Web\Rates;

use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Actions\CreateRatePlan;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rates\RatePlanRequest;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
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
                'id' => $plan->id, 'name' => $plan->name, 'code' => $plan->code, 'currency' => $plan->currency->value,
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
        /** @var array{name: string, code: string, currency?: string, room_type_ids?: list<string>, base_rate_plan_id?: string, adjustment_kind?: string, adjustment?: int|string} $details */
        $details = $request->validated();
        if (isset($details['adjustment'])) {
            $details['adjustment'] = (int) $details['adjustment'];
        }
        $create->handle($actor, $details);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rate plan added.')]);

        return redirect()->route('rate-plans.index');
    }
}
