<?php

namespace App\Http\Controllers\Web\FrontDesk;

use App\Domains\FrontDesk\Actions\MoveRoom;
use App\Domains\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Requests\FrontDesk\RoomMoveRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class RoomMoveController extends Controller
{
    public function __invoke(RoomMoveRequest $request, Reservation $reservation, MoveRoom $move): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $move->handle($user, $reservation, $request->validated('room_id'), $request->validated('pricing') === 'reprice');

        return to_route('reservations.show', $reservation)->with('toast', ['type' => 'success', 'message' => __('Guest moved room.')]);
    }
}
