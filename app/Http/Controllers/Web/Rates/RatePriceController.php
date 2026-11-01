<?php

namespace App\Http\Controllers\Web\Rates;

use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Actions\SetRatePrices;
use App\Domains\Rates\Enums\ChargeKind;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Rates\Models\RatePrice;
use App\Domains\Rates\Models\TaxRule;
use App\Domains\Rates\StayPricing;
use App\Domains\Rates\TaxCalculator;
use App\Domains\Tenancy\Models\Property;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rates\BulkPriceRequest;
use App\Http\Requests\Rates\QuoteRequest;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\ExchangeRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class RatePriceController extends Controller
{
    public function index(RatePlan $ratePlan): Response
    {
        Gate::authorize('view', $ratePlan);

        $base = $ratePlan->basePlan;
        $roomTypes = $ratePlan->roomTypes()->orderBy('code')->get(['room_types.id', 'name', 'code', 'base_occupancy']);
        $currency = $ratePlan->currency;
        $today = Property::query()->findOrFail($ratePlan->property_id)->business_date->toDateString();
        $rules = TaxRule::query()->where('property_id', $ratePlan->property_id)->orderBy('name')->get()->all();
        $rate = $currency === Currency::USD ? null : ExchangeRate::forDate($today);
        // What the guest pays for a night at base occupancy: the price plus any tax added on top.
        $guestPays = function (int $amount, string $date, int $guests) use ($rules, $currency, $rate): ?int {
            try {
                return TaxCalculator::breakdown($rules, $amount, $currency, $date, $guests, ChargeKind::Room, $rate)->gross();
            } catch (InvalidArgumentException) {
                return null;
            }
        };
        try {
            // Rates and per-guest amounts do not depend on the price, so the largest allowed price shows today's terms.
            $taxes = TaxCalculator::breakdown($rules, 1000000000, $currency, $today, 1, ChargeKind::Room, $rate)->postedLines();
        } catch (InvalidArgumentException) {
            $taxes = [];
        }

        return Inertia::render('rates/prices', [
            'plan' => ['id' => $ratePlan->id, 'name' => $ratePlan->name, 'currency' => $currency->value],
            'base' => $base === null ? null : ['id' => $base->id, 'name' => $base->name, 'adjustmentKind' => $ratePlan->adjustment_kind?->value, 'adjustment' => $ratePlan->adjustment],
            'roomTypes' => $roomTypes->map(fn (RoomType $roomType) => $roomType->only(['id', 'name', 'code', 'base_occupancy'])),
            'prices' => RatePrice::query()->where('rate_plan_id', $base->id ?? $ratePlan->id)->orderBy('date')->orderBy('room_type_id')->get()->map($ratePlan->priced(...))->map(fn (RatePrice $price) => [
                'room_type_id' => $price->room_type_id, 'date' => $price->date->format('Y-m-d'), 'base_price' => $price->base_price, 'extra_adult' => $price->extra_adult, 'extra_child' => $price->extra_child,
                'guest_pays' => $guestPays($price->base_price, $price->date->format('Y-m-d'), (int) ($roomTypes->firstWhere('id', $price->room_type_id)->base_occupancy ?? 1)),
            ]),
            'taxes' => $taxes,
            'canManage' => $base === null && Gate::allows('update', $ratePlan),
        ]);
    }

    public function update(BulkPriceRequest $request, RatePlan $ratePlan, SetRatePrices $set): RedirectResponse
    {
        /** @var User $actor */ $actor = $request->user();
        $set->handle($actor, $ratePlan, $request->details());
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
