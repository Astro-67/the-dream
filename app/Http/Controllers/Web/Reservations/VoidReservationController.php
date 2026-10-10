<?php

namespace App\Http\Controllers\Web\Reservations;

use App\Domains\Reservations\Actions\VoidReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\VoidReservationRequest;
use Illuminate\Http\RedirectResponse;

class VoidReservationController extends Controller
{
    public function __invoke(VoidReservationRequest $request, Reservation $reservation, VoidReservation $void): RedirectResponse
    {
        $void->handle($request->user(), $reservation, $request->validated('reason'));

        return to_route('reservations.show', $reservation)->with('toast', ['type' => 'success', 'message' => __('Reservation voided.')]);
    }
}
