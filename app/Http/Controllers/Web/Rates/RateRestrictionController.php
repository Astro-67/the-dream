<?php

namespace App\Http\Controllers\Web\Rates;

use App\Domains\Rates\Actions\SetRateRestrictions;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Rates\Models\RateRestriction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rates\BulkRestrictionRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RateRestrictionController extends Controller
{
    public function index(RatePlan $ratePlan): Response
    {
        Gate::authorize('view', $ratePlan);

        return Inertia::render('rates/restrictions', [
            'plan' => ['id' => $ratePlan->id, 'name' => $ratePlan->name],
            'roomTypes' => $ratePlan->roomTypes()->orderBy('code')->get(['room_types.id', 'name', 'code']),
            'restrictions' => RateRestriction::query()->where('rate_plan_id', $ratePlan->id)->orderBy('date')->orderBy('room_type_id')->get()
                ->map(fn (RateRestriction $restriction) => ['room_type_id' => $restriction->room_type_id, 'date' => $restriction->date->format('Y-m-d'), 'min_stay' => $restriction->min_stay, 'closed_to_arrival' => $restriction->closed_to_arrival, 'stop_sell' => $restriction->stop_sell]),
            'canManage' => Gate::allows('update', $ratePlan),
        ]);
    }

    public function update(BulkRestrictionRequest $request, RatePlan $ratePlan, SetRateRestrictions $set): RedirectResponse
    {
        /** @var User $actor */ $actor = $request->user();
        $set->handle($actor, $ratePlan, $request->details());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Restrictions saved.')]);

        return redirect()->route('rate-plans.restrictions', $ratePlan);
    }
}
