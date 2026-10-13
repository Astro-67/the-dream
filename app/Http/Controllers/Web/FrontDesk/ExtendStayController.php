<?php

namespace App\Http\Controllers\Web\FrontDesk;

use App\Domains\FrontDesk\Actions\ExtendStay;
use App\Domains\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Requests\FrontDesk\ExtendStayRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class ExtendStayController extends Controller
{
    public function __invoke(ExtendStayRequest $request, Reservation $reservation, ExtendStay $extend): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $extend->handle($user, $reservation, $request->validated('departure'));

        return to_route('reservations.show', $reservation)->with('toast', ['type' => 'success', 'message' => __('Stay extended.')]);
    }
}
