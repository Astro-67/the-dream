<?php

namespace App\Http\Controllers\Web\FrontDesk;

use App\Domains\FrontDesk\Actions\DepartEarly;
use App\Domains\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Requests\FrontDesk\DepartEarlyRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class DepartEarlyController extends Controller
{
    public function __invoke(DepartEarlyRequest $request, Reservation $reservation, DepartEarly $departEarly): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $departEarly->handle($user, $reservation);

        return to_route('reservations.show', $reservation)->with('toast', ['type' => 'success', 'message' => __('Early departure recorded.')]);
    }
}
