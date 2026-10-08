<?php

namespace App\Http\Controllers\Web\Rates;

use App\Domains\Rates\Actions\SetRatePrices;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Rates\Models\RatePrice;
use App\Domains\Rates\StayPricing;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rates\BulkPriceRequest;
use App\Http\Requests\Rates\QuoteRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RatePriceController extends Controller
{
    public function index(RatePlan $ratePlan): Response
    {
        Gate::authorize('view', $ratePlan);

        $base = $ratePlan->basePlan;

        return Inertia::render('rates/prices', [
            'plan' => ['id' => $ratePlan->id, 'name' => $ratePlan->name, 'currency' => $ratePlan->currency->value],
            'base' => $base === null ? null : ['id' => $base->id, 'name' => $base->name, 'adjustmentKind' => $ratePlan->adjustment_kind?->value, 'adjustment' => $ratePlan->adjustment],
            'roomTypes' => $ratePlan->roomTypes()->orderBy('code')->get(['room_types.id', 'name', 'code']),
            'prices' => RatePrice::query()->where('rate_plan_id', $base->id ?? $ratePlan->id)->orderBy('date')->orderBy('room_type_id')->get()->map($ratePlan->priced(...))->map(fn (RatePrice $price) => ['room_type_id' => $price->room_type_id, 'date' => $price->date->format('Y-m-d'), 'base_price' => $price->base_price, 'extra_adult' => $price->extra_adult, 'extra_child' => $price->extra_child]),
            'canManage' => $base === null && Gate::allows('update', $ratePlan),
        ]);
    }

    public function update(BulkPriceRequest $request, RatePlan $ratePlan, SetRatePrices $set): RedirectResponse
    {
        /** @var User $actor */ $actor = $request->user();
        /** @var array{room_type_id: string, from: string, to: string, weekdays: list<int>, base_price: int, extra_adult: int, extra_child: int} $details */
        $details = $request->validated();
        $details['weekdays'] = array_map(intval(...), $details['weekdays']);
        $set->handle($actor, $ratePlan, $details);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Prices saved.')]);

        return redirect()->route('rate-plans.prices', $ratePlan);
    }

    public function quote(QuoteRequest $request, RatePlan $ratePlan, StayPricing $pricing): Response
    {
        /** @var array{room_type_id?: string, arrival?: string, departure?: string, adults?: int|string, children?: int|string} $stay */
        $stay = $request->validated();
        $quote = isset($stay['room_type_id'], $stay['arrival'], $stay['departure'], $stay['adults'], $stay['children'])
            ? $pricing->quote($ratePlan, $ratePlan->roomTypes()->findOrFail($stay['room_type_id']), $stay['arrival'], $stay['departure'], (int) $stay['adults'], (int) $stay['children'])
            : null;

        return Inertia::render('rates/quote', [
            'plan' => ['id' => $ratePlan->id, 'name' => $ratePlan->name, 'currency' => $ratePlan->currency->value],
            'roomTypes' => $ratePlan->roomTypes()->orderBy('code')->get(['room_types.id', 'name', 'code']),
            'stay' => $stay,
            'quote' => $quote === null ? null : ['currency' => $quote->currency->value, 'nights' => $quote->nights, 'total' => $quote->total(), 'unquotable' => $quote->unquotable],
            'canManage' => Gate::allows('update', $ratePlan),
        ]);
    }
}
