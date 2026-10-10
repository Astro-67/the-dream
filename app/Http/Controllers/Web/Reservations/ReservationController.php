<?php

namespace App\Http\Controllers\Web\Reservations;

use App\Domains\Guests\Models\Booker;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Rates\StayRestrictions;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\CreateReservationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ReservationController extends Controller
{
    public function create(CreateReservationRequest $request, StayRestrictions $restrictions): Response
    {
        $type = RoomType::query()->findOrFail($request->validated('room_type_id'));
        $plan = RatePlan::query()->findOrFail($request->validated('rate_plan_id'));

        return Inertia::render('reservations/create', [
            'stay' => $request->validated(),
            'roomType' => $type->only(['id', 'name']),
            'ratePlan' => $plan->only(['id', 'name']),
            'restrictionWarnings' => $restrictions->warnings($plan, $type, $request->validated('arrival'), $request->validated('departure')),
            'bookers' => Booker::query()->orderBy('name')->paginate(50)->withQueryString(),
        ]);
    }

    public function store(CreateReservationRequest $request, CreateReservation $create): RedirectResponse
    {
        $reservation = $create->handle($request->user(), $request->validated());

        return to_route('reservations.show', $reservation)->with('toast', ['type' => 'success', 'message' => __('Reservation created.')]);
    }

    public function show(Reservation $reservation): Response
    {
        Gate::authorize('view', $reservation);

        return Inertia::render('reservations/show', [
            'reservation' => $reservation->load('priceLines'),
            'groupMembers' => $reservation->getAttribute('booking_group_id') === null ? [] : Reservation::query()
                ->where('property_id', $reservation->property_id)
                ->where('booking_group_id', $reservation->getAttribute('booking_group_id'))
                ->whereKeyNot($reservation->id)->orderBy('reservation_number')
                ->get(['reservation_number', 'status', 'arrival', 'departure']),
            'can' => ['cancel' => Gate::allows('cancel', $reservation), 'void' => Gate::allows('void', $reservation)],
            'booker' => Booker::query()->findOrFail($reservation->getAttribute('booker_id'))->only(['id', 'name']),
            'roomType' => RoomType::query()->findOrFail($reservation->getAttribute('room_type_id'))->only(['id', 'name']),
            'ratePlan' => RatePlan::query()->findOrFail($reservation->getAttribute('rate_plan_id'))->only(['id', 'name']),
        ]);
    }
}
