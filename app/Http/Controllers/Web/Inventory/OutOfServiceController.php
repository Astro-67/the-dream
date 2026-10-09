<?php

namespace App\Http\Controllers\Web\Inventory;

use App\Domains\Inventory\Actions\ChangeOutOfServicePeriod;
use App\Domains\Inventory\Actions\TakeRoomOutOfService;
use App\Domains\Inventory\Models\OutOfServicePeriod;
use App\Domains\Inventory\Models\Room;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\OutOfServiceRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * A room's out-of-service periods: take it out for a date range, then change or end the period early.
 */
class OutOfServiceController extends Controller
{
    public function store(OutOfServiceRequest $request, Room $room, TakeRoomOutOfService $takeOutOfService): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{starts_on: string, returns_on: string, reason: string} $details */
        $details = $request->validated();
        $takeOutOfService->handle($user, $room, $details);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Room :number taken out of service.', ['number' => $room->number])]);

        return redirect()->route('rooms.edit', $room);
    }

    public function update(OutOfServiceRequest $request, OutOfServicePeriod $period, ChangeOutOfServicePeriod $changePeriod): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{starts_on: string, returns_on: string, reason: string} $details */
        $details = $request->validated();
        $changePeriod->handle($user, $period, $details);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Out-of-service period saved.')]);

        return redirect()->route('rooms.edit', $period->room_id);
    }
}
