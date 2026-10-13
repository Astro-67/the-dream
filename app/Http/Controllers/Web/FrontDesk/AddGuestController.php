<?php

namespace App\Http\Controllers\Web\FrontDesk;

use App\Domains\FrontDesk\Actions\AddGuest;
use App\Domains\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Requests\FrontDesk\AddGuestRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class AddGuestController extends Controller
{
    public function __invoke(AddGuestRequest $request, Reservation $reservation, AddGuest $addGuest): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $addGuest->handle($user, $reservation, $request->validated());

        return to_route('reservations.guests.create', $reservation)->with('toast', ['type' => 'success', 'message' => __('Guest added.')]);
    }
}
