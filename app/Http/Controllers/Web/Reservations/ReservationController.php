<?php

namespace App\Http\Controllers\Web\Reservations;

use App\Domains\Guests\Models\Booker;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\AssignableRooms;
use App\Domains\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\CreateReservationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ReservationController extends Controller
{
    public function create(CreateReservationRequest $request): Response
    {
        return Inertia::render('reservations/create', [
            'stay' => $request->validated(),
            'rooms' => app(AssignableRooms::class)->forStay($request->validated('room_type_id'), $request->validated('arrival'), $request->validated('departure')),
            'roomType' => RoomType::query()->findOrFail($request->validated('room_type_id'))->only(['id', 'name']),
            'ratePlan' => RatePlan::query()->findOrFail($request->validated('rate_plan_id'))->only(['id', 'name']),
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
            'rooms' => app(AssignableRooms::class)->forStay($reservation->getAttribute('room_type_id'), $reservation->arrival->toDateString(), $reservation->departure->toDateString(), $reservation->id),
            'canAssignRoom' => Gate::allows('assignRoom', $reservation),
            'booker' => Booker::query()->findOrFail($reservation->getAttribute('booker_id'))->only(['id', 'name']),
            'roomType' => RoomType::query()->findOrFail($reservation->getAttribute('room_type_id'))->only(['id', 'name']),
            'ratePlan' => RatePlan::query()->findOrFail($reservation->getAttribute('rate_plan_id'))->only(['id', 'name']),
        ]);
    }
}
