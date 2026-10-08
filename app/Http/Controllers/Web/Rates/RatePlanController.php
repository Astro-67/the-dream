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
            'ratePlans' => RatePlan::query()->with('roomTypes')->orderBy('code')->get()->map(fn (RatePlan $plan) => [
                'id' => $plan->id, 'name' => $plan->name, 'code' => $plan->code, 'currency' => $plan->currency->value,
                'roomTypes' => $plan->roomTypes->map->only(['id', 'name', 'code']),
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
        ]);
    }

    public function store(RatePlanRequest $request, CreateRatePlan $create): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        /** @var array{name: string, code: string, currency: string, room_type_ids: list<string>} $details */
        $details = $request->validated();
        $create->handle($actor, $details);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rate plan added.')]);

        return redirect()->route('rate-plans.index');
    }
}
