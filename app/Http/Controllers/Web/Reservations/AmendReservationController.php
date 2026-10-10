<?php

namespace App\Http\Controllers\Web\Reservations;

use App\Domains\Reservations\Actions\AmendReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\AmendReservationRequest;
use Illuminate\Http\RedirectResponse;

class AmendReservationController extends Controller
{
    public function __invoke(AmendReservationRequest $request, Reservation $reservation, AmendReservation $amend): RedirectResponse
    {
        $amend->handle($request->user(), $reservation, $request->validated());

        return to_route('reservations.show', $reservation)->with('toast', ['type' => 'success', 'message' => __('Reservation amended.')]);
    }
}
