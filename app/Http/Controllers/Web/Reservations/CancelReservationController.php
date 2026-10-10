<?php

namespace App\Http\Controllers\Web\Reservations;

use App\Domains\Reservations\Actions\CancelReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\CancelReservationRequest;
use Illuminate\Http\RedirectResponse;

class CancelReservationController extends Controller
{
    public function __invoke(CancelReservationRequest $request, Reservation $reservation, CancelReservation $cancel): RedirectResponse
    {
        $cancel->handle($request->user(), $reservation, $request->validated('reason'));

        return to_route('reservations.show', $reservation)->with('toast', ['type' => 'success', 'message' => __('Reservation cancelled.')]);
    }
}
