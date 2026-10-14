<?php

namespace App\Http\Controllers\Web\Housekeeping;

use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Housekeeping\Actions\ReconcileRoomServiceStatuses;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Housekeeping\Models\StayoverService;
use App\Domains\Housekeeping\RoomReadiness;
use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Housekeeping\BoardRequest;
use App\Models\User;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BoardController extends Controller
{
    public function index(BoardRequest $request): Response
    {
        Gate::authorize('viewAny', RoomStatus::class);
        /** @var User $user */
        $user = $request->user();

        $filters = $request->validated();
        app(ReconcileRoomServiceStatuses::class)->handle();
        $businessDate = BusinessDate::current();
        $serviceRooms = StayoverService::query()->whereDate('business_date', $businessDate)->pluck('room_id');
        $rooms = RoomStatus::query()->with('room.roomType')->get()->sortBy(fn (RoomStatus $status) => $status->room->number)->values();
        $floors = $rooms->map(fn (RoomStatus $status) => $status->room->floor)->unique()->sort()->values();

        return Inertia::render('housekeeping/index', [
            'businessDate' => $businessDate,
            'stayoverServiceLabel' => __('Stayover service required'),
            'filters' => ['status' => $filters['status'] ?? '', 'floor' => $filters['floor'] ?? ''],
            'statuses' => array_map(fn ($status) => ['value' => $status->value, 'label' => $status->label()], HousekeepingStatus::cases()),
            'floors' => $floors->map(fn ($floor) => ['value' => $floor ?? '__none', 'label' => $floor ?? __('No floor')]),
            'rooms' => $rooms->filter(fn (RoomStatus $status) => (empty($filters['status']) || $status->status->value === $filters['status'])
                && (! isset($filters['floor']) || $filters['floor'] === '' || ($status->room->floor ?? '__none') === $filters['floor']))->values()->map(fn (RoomStatus $status) => [
                    'id' => $status->room_id,
                    'number' => $status->room->number,
                    'floor' => $status->room->floor,
                    'roomType' => $status->room->roomType->only(['code', 'name']),
                    'status' => $status->status->value,
                    'statusLabel' => $status->status->label(),
                    'stayoverService' => $status->status === HousekeepingStatus::Occupied && $serviceRooms->contains($status->room_id),
                    'isReady' => app(RoomReadiness::class)->isReady($status->room),
                    'readyOverride' => app(RoomReadiness::class)->overrideFor($status->room)?->only(['reason', 'business_date']),
                ]),
            'inspectionRequired' => Tenancy::property()->housekeeping_inspection_required,
            'canManage' => Access::allows($user, OrganizationPermission::HousekeepingManage, Tenancy::property()),
            'canOverrideReady' => Access::allows($user, OrganizationPermission::HousekeepingOverrideReady, Tenancy::property()),
            'canSupervise' => $user->can('supervise', RoomStatus::class),
        ]);
    }
}
