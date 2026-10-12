<?php

namespace App\Http\Controllers\Web\Housekeeping;

use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BoardController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', RoomStatus::class);
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('housekeeping/index', [
            'rooms' => RoomStatus::query()->with('room.roomType')->get()->sortBy(fn (RoomStatus $status) => $status->room->number)->values()->map(fn (RoomStatus $status) => [
                'id' => $status->room_id,
                'number' => $status->room->number,
                'floor' => $status->room->floor,
                'roomType' => $status->room->roomType->only(['code', 'name']),
                'status' => $status->status->value,
                'statusLabel' => $status->status->label(),
            ]),
            'canManage' => Access::allows($user, OrganizationPermission::HousekeepingManage, Tenancy::property()),
            'canSupervise' => $user->can('supervise', RoomStatus::class),
        ]);
    }
}
