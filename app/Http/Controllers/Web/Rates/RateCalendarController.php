<?php

namespace App\Http\Controllers\Web\Rates;

use App\Domains\Inventory\Models\RoomType;
use App\Domains\Inventory\RoomAvailability;
use App\Domains\Rates\Actions\SetRatePrices;
use App\Domains\Rates\Actions\SetRateRestrictions;
use App\Domains\Rates\DateRange;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Rates\Models\RatePrice;
use App\Domains\Rates\Models\RateRestriction;
use App\Domains\Rates\StayPricing;
use App\Domains\Reservations\ReservationCalendar;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rates\BulkPriceRequest;
use App\Http\Requests\Rates\BulkRestrictionRequest;
use App\Http\Requests\Rates\RateCalendarRequest;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Bulk edits here use the rate plan pages' requests and actions, so validation and audit match. */
class RateCalendarController extends Controller
{
    public function index(RateCalendarRequest $request, RoomAvailability $availability, StayPricing $pricing, ReservationCalendar $calendar): Response
    {
        $today = CarbonImmutable::now(Tenancy::property()?->timezone)->startOfDay();
        $from = $request->string('from', $today->toDateString())->value();
        $to = $request->string('to', $today->addDays(13)->toDateString())->value();
        $dates = DateRange::dates($from, $to);
        $empty = array_fill_keys($dates, null);
        $allRoomTypes = RoomType::query()->orderBy('code')->get(['id', 'name', 'code']);
        $selected = $request->has('room_type_ids') ? $allRoomTypes->whereIn('id', (array) $request->input('room_type_ids')) : $allRoomTypes;
        $plans = RatePlan::query()->whereNull('deactivated_at')->with('roomTypes:id')->orderBy('code')->get();
        $restrictions = RateRestriction::query()->whereIn('room_type_id', $selected->pluck('id'))->whereBetween('date', [$from, $to])->get()
            ->groupBy(fn (RateRestriction $r) => $r->rate_plan_id.'|'.$r->room_type_id);

        $reservations = $calendar->forRange($selected->pluck('id')->all(), $from, $to);

        return Inertia::render('rates/calendar', [
            'from' => $from,
            'to' => $to,
            'dates' => $dates,
            'allRoomTypes' => $allRoomTypes,
            'roomTypes' => $selected->values()->map(fn (RoomType $type) => [
                ...$type->only(['id', 'name', 'code']),
                ...$reservations->get($type->id, []),
                'sellable' => $availability->sellableByDate($type, CarbonImmutable::parse($from), CarbonImmutable::parse($to)),
                'outOfService' => $availability->outOfServiceByDate($type, CarbonImmutable::parse($from), CarbonImmutable::parse($to)),
                'plans' => $plans->filter(fn (RatePlan $plan) => $plan->roomTypes->contains('id', $type->id))->values()->map(fn (RatePlan $plan) => [
                    'id' => $plan->id, 'name' => $plan->name, 'code' => $plan->code, 'currency' => $plan->currency->value,
                    'derived' => $plan->base_rate_plan_id !== null,
                    'prices' => [...$empty, ...$pricing->storedPrices($plan, $type, $dates)->map(fn (RatePrice $price) => $price->base_price)->all()],
                    'restrictions' => [...$empty, ...($restrictions->get($plan->id.'|'.$type->id)?->mapWithKeys(fn (RateRestriction $r) => [
                        $r->date->format('Y-m-d') => ['min_stay' => $r->min_stay, 'closed_to_arrival' => $r->closed_to_arrival, 'stop_sell' => $r->stop_sell],
                    ])->all() ?? [])],
                ]),
            ]),
            'canManage' => Gate::allows('create', RatePlan::class),
        ]);
    }

    public function prices(BulkPriceRequest $request, RatePlan $ratePlan, SetRatePrices $set): RedirectResponse
    {
        /** @var User $actor */ $actor = $request->user();
        $set->handle($actor, $ratePlan, $request->details());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Prices saved.')]);

        return back();
    }

    public function restrictions(BulkRestrictionRequest $request, RatePlan $ratePlan, SetRateRestrictions $set): RedirectResponse
    {
        /** @var User $actor */ $actor = $request->user();
        $set->handle($actor, $ratePlan, $request->details());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Restrictions saved.')]);

        return back();
    }
}
