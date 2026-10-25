<?php

namespace App\Http\Controllers\Web\FrontDesk;

use App\Domains\FrontDesk\Actions\GuestLeaves;
use App\Domains\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Requests\FrontDesk\GuestLeavesRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class GuestLeavesController extends Controller
{
    public function __invoke(GuestLeavesRequest $request, Reservation $reservation, GuestLeaves $guestLeaves): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $guest = $guestLeaves->handle($user, $reservation, $request->validated('guest_id'));

        return to_route('reservations.guests.create', $reservation)->with('toast', ['type' => 'success', 'message' => __(':guest has left.', ['guest' => $guest->name])]);
    }
}
