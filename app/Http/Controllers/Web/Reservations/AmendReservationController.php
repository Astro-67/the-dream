<?php

namespace App\Http\Controllers\Web\Reservations;

use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Rates\StayRestrictions;
use App\Domains\Reservations\Actions\AmendReservation;
use App\Domains\Reservations\BookingRules;
use App\Domains\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\AmendReservationRequest;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class AmendReservationController extends Controller
{
    public function preview(AmendReservationRequest $request, Reservation $reservation, StayRestrictions $restrictions): JsonResponse
    {
        return response()->json(['warnings' => [...app(BookingRules::class)->warnings(Tenancy::property(), $request->validated('arrival'), $request->validated('departure')), ...$restrictions->warnings(
            RatePlan::query()->findOrFail($request->validated('rate_plan_id')),
            RoomType::query()->findOrFail($request->validated('room_type_id')),
            $request->validated('arrival'),
            $request->validated('departure'),
        )]]);
    }

    public function __invoke(AmendReservationRequest $request, Reservation $reservation, AmendReservation $amend): RedirectResponse
    {
        $amend->handle($request->user(), $reservation, $request->validated());

        return to_route('reservations.show', $reservation)->with('toast', ['type' => 'success', 'message' => __('Reservation amended.')]);
    }
}
