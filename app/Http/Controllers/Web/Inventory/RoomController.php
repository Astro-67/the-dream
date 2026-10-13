<?php

namespace App\Http\Controllers\Web\Inventory;

use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\DeactivateRoom;
use App\Domains\Inventory\Actions\MoveRoom;
use App\Domains\Inventory\Actions\UpdateRoom;
use App\Domains\Inventory\Models\OutOfServicePeriod;
use App\Domains\Inventory\Models\Room;
use App\Domains\Inventory\Models\RoomType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\RoomRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The current property's rooms: list, add, edit, move to another room type and deactivate.
 */
class RoomController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Room::class);

        /** @var User $user */
        $user = $request->user();

        $rooms = Room::query()->with('roomType')->orderBy('number')->get();
        $row = fn (Room $room) => [
            'id' => $room->id,
            'number' => $room->number,
            'floor' => $room->floor,
            'roomType' => ['id' => $room->roomType->id, 'code' => $room->roomType->code, 'name' => $room->roomType->name],
            'deactivatedAt' => $room->deactivated_at?->toIso8601String(),
        ];

        return Inertia::render('inventory/rooms/index', [
            'canManage' => $user->can('create', Room::class),
            'roomTypes' => $this->roomTypeOptions(),
            'rooms' => $rooms->whereNull('deactivated_at')->map($row)->values(),
            // Kept for history; left out of every active list.
            'deactivatedRooms' => $rooms->whereNotNull('deactivated_at')->map($row)->values(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Room::class);

        return Inertia::render('inventory/rooms/create', ['roomTypes' => $this->roomTypeOptions()]);
    }

    public function store(RoomRequest $request, CreateRoom $createRoom): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $room = $createRoom->handle($user, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Room :number added.', ['number' => $room->number])]);

        return redirect()->route('rooms.index');
    }

    public function edit(Room $room): Response
    {
        Gate::authorize('update', $room);

        $today = BusinessDate::current();

        return Inertia::render('inventory/rooms/edit', [
            'room' => $room->only(['id', 'number', 'floor', 'room_type_id']),
            'roomTypes' => $this->roomTypeOptions(),
            'today' => $today,
            // Current and upcoming only; ended periods stay in the audit trail.
            'outOfService' => $room->outOfServicePeriods()->whereDate('returns_on', '>', $today)->orderBy('starts_on')->get()
                ->map(fn (OutOfServicePeriod $period) => [
                    'id' => $period->id,
                    'startsOn' => $period->starts_on->toDateString(),
                    'returnsOn' => $period->returns_on->toDateString(),
                    'reason' => $period->reason,
                    'started' => $period->starts_on->toDateString() <= $today,
                ])->values(),
        ]);
    }

    public function update(RoomRequest $request, Room $room, UpdateRoom $updateRoom): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $updateRoom->handle($user, $room, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Room :number saved.', ['number' => $room->number])]);

        return redirect()->route('rooms.index');
    }

    public function move(RoomRequest $request, Room $room, MoveRoom $moveRoom): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $roomType = RoomType::query()->findOrFail($request->validated('room_type_id'));
        $moveRoom->handle($user, $room, $roomType);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Room :number moved to :type.', ['number' => $room->number, 'type' => $roomType->name])]);

        return redirect()->route('rooms.index');
    }

    public function deactivate(Request $request, Room $room, DeactivateRoom $deactivateRoom): RedirectResponse
    {
        Gate::authorize('update', $room);

        /** @var User $user */
        $user = $request->user();

        $deactivateRoom->handle($user, $room);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Room :number deactivated.', ['number' => $room->number])]);

        return redirect()->route('rooms.index');
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function roomTypeOptions(): array
    {
        return RoomType::query()->orderBy('code')->get()
            ->map(fn (RoomType $roomType) => ['value' => $roomType->id, 'label' => "{$roomType->code} · {$roomType->name}"])
            ->values()
            ->all();
    }
}
