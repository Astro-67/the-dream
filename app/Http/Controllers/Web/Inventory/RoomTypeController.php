<?php

namespace App\Http\Controllers\Web\Inventory;

use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Actions\UpdateRoomType;
use App\Domains\Inventory\Models\RoomType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\RoomTypeRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The current property's room types: list, add and edit.
 */
class RoomTypeController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', RoomType::class);

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('inventory/room-types/index', [
            'canManage' => $user->can('create', RoomType::class),
            'roomTypes' => RoomType::query()
                ->withCount(['rooms' => fn ($query) => $query->whereNull('deactivated_at')])
                ->orderBy('code')
                ->get()
                ->map(fn (RoomType $roomType) => [
                    'id' => $roomType->id,
                    'name' => $roomType->name,
                    'code' => $roomType->code,
                    'baseOccupancy' => $roomType->base_occupancy,
                    'maxAdults' => $roomType->max_adults,
                    'maxChildren' => $roomType->max_children,
                    'maxOccupancy' => $roomType->max_occupancy,
                    'activeRooms' => $roomType->getAttribute('rooms_count'),
                ]),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', RoomType::class);

        return Inertia::render('inventory/room-types/create');
    }

    public function store(RoomTypeRequest $request, CreateRoomType $createRoomType): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $roomType = $createRoomType->handle($user, $request->details());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added.', ['name' => $roomType->name])]);

        return redirect()->route('room-types.index');
    }

    public function edit(RoomType $roomType): Response
    {
        Gate::authorize('update', $roomType);

        return Inertia::render('inventory/room-types/edit', [
            'roomType' => ['id' => $roomType->id, ...$roomType->only(RoomType::FIELDS)],
        ]);
    }

    public function update(RoomTypeRequest $request, RoomType $roomType, UpdateRoomType $updateRoomType): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $updateRoomType->handle($user, $roomType, $request->details());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name saved.', ['name' => $roomType->name])]);

        return redirect()->route('room-types.index');
    }
}
