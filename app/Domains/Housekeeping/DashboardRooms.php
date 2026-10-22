<?php

namespace App\Domains\Housekeeping;

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Models\Reservation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class DashboardRooms
{
    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function forDate(string $date): LengthAwarePaginator
    {
        return Room::query()->active()->with('roomType')->addSelect([
            'housekeeping_status' => RoomStatus::query()->select('status')->whereColumn('room_id', 'rooms.id')->limit(1),
            'occupied' => Reservation::query()->select('id')->whereColumn('room_id', 'rooms.id')->where('status', 'checked_in')->whereDate('arrival', '<=', $date)->limit(1),
            'reserved' => Reservation::query()->select('id')->whereColumn('room_id', 'rooms.id')->where('status', 'confirmed')->whereDate('arrival', $date)->limit(1),
        ])->withExists(['outOfServicePeriods as out_of_order' => fn (Builder $query) => $query
            ->whereDate('starts_on', '<=', $date)->whereDate('returns_on', '>', $date),
        ])->orderBy('number')->orderBy('id')->paginate(50)->withQueryString()->through(function (Room $room) {
            $housekeeping = HousekeepingStatus::tryFrom($room->getAttribute('housekeeping_status') ?? '') ?? HousekeepingStatus::Dirty;
            [$status, $label, $tone] = match (true) {
                $room->getAttribute('out_of_order') || $housekeeping === HousekeepingStatus::Unavailable => ['out_of_order', __('Out of order'), 'unavailable'],
                $room->getAttribute('occupied') !== null || $housekeeping === HousekeepingStatus::Occupied => ['occupied', __('Occupied'), 'occupied'],
                $room->getAttribute('reserved') !== null => ['reserved', __('Reserved'), 'info'],
                $housekeeping === HousekeepingStatus::Clean => ['available', __('Available'), 'success'],
                default => [$housekeeping->value, $housekeeping->label(), $housekeeping->tone()],
            };

            return [
                'id' => $room->id,
                'number' => $room->number,
                'roomType' => $room->roomType->name,
                'status' => $status,
                'statusLabel' => $label,
                'tone' => $tone,
                'readinessLabel' => $status === 'reserved' && $housekeeping !== HousekeepingStatus::Clean ? $housekeeping->label() : null,
                'readinessTone' => $housekeeping->tone(),
            ];
        });
    }
}
