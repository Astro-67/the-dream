<?php

namespace App\Http\Controllers\Web\Housekeeping;

use App\Domains\Housekeeping\Actions\ChangeRoomStatus;
use App\Domains\Housekeeping\Actions\OverrideRoomReady;
use App\Http\Requests\Housekeeping\OverrideRoomReadyRequest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Housekeeping\Models\StatusChange;
use App\Domains\Inventory\Models\Room;
use App\Http\Controllers\Controller;
use App\Http\Requests\Housekeeping\ChangeRoomStatusRequest;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RoomStatusController extends Controller
{
    public function history(Room $room): Response
    {
        Gate::authorize('view', RoomStatus::query()->where('room_id', $room->id)->sole());

        return Inertia::render('housekeeping/history', [
            'room' => $room->only(['id', 'number']),
            'changes' => StatusChange::query()->where('room_id', $room->id)->with('actor')->orderByDesc('changed_at')->orderByDesc('id')->paginate(50)->withQueryString()
                ->through(fn (StatusChange $change) => [
                    'kind' => $change->kind, 'businessDate' => $change->business_date?->toDateString(),
                    'id' => $change->id, 'from' => $change->from_status->label(), 'to' => $change->to_status->label(),
                    'reason' => $change->reason, 'actor' => $change->actor->name ?? __('System'),
                    'changedAt' => $change->changed_at->toIso8601String(),
                    'changedOn' => $change->changed_at->setTimezone(Tenancy::property()->timezone ?? 'UTC')->toDateString(),
                ]),
            'timezone' => Tenancy::property()?->timezone,
        ]);
    }

    public function overrideReady(OverrideRoomReadyRequest $request, Room $room, OverrideRoomReady $override): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $override->handle($user, $room, $request->validated('reason'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Room :number readiness overridden for this business date.', ['number' => $room->number])]);

        return back();
    }

    public function update(ChangeRoomStatusRequest $request, Room $room, ChangeRoomStatus $change): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $change->handle($user, $room, HousekeepingStatus::from($request->validated('status')), $request->validated('reason'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Room :number status updated.', ['number' => $room->number])]);

        return back();
    }
}
