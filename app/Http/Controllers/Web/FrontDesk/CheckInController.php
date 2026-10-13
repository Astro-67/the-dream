<?php

namespace App\Http\Controllers\Web\FrontDesk;

use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Requests\FrontDesk\CheckInRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class CheckInController extends Controller
{
    public function __invoke(CheckInRequest $request, Reservation $reservation, CheckIn $checkIn): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $checkIn->handle($user, $reservation);

        return to_route('reservations.show', $reservation)->with('toast', ['type' => 'success', 'message' => __('Guest checked in.')]);
    }
}
