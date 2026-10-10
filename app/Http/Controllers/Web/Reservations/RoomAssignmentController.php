<?php

namespace App\Http\Controllers\Web\Reservations;

use App\Domains\Reservations\Actions\AssignRoom;
use App\Domains\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\AssignRoomRequest;
use Illuminate\Http\RedirectResponse;

class RoomAssignmentController extends Controller
{
    public function __invoke(AssignRoomRequest $request, Reservation $reservation, AssignRoom $assign): RedirectResponse
    {
        $assign->handle($request->user(), $reservation, $request->validated('room_id'));

        return to_route('reservations.show', $reservation)->with('toast', ['type' => 'success', 'message' => __('Room assignment updated.')]);
    }
}
