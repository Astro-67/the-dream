<?php

namespace App\Http\Controllers\Web\FrontDesk;

use App\Domains\FrontDesk\Actions\CheckOut;
use App\Domains\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Requests\FrontDesk\CheckOutRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class CheckOutController extends Controller
{
    public function __invoke(CheckOutRequest $request, Reservation $reservation, CheckOut $checkOut): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $checkOut->handle($user, $reservation, $request->validated('override_reason'));

        return to_route('reservations.show', $reservation)->with('toast', ['type' => 'success', 'message' => __('Guest checked out.')]);
    }
}
