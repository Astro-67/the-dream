<?php

namespace App\Domains\Housekeeping;

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use Illuminate\Pagination\LengthAwarePaginator;

class DashboardRooms
{
    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function forDate(string $date): LengthAwarePaginator
    {
        return Room::query()->active()->with('roomType')->addSelect([
            'housekeeping_status' => RoomStatus::query()->select('status')->whereColumn('room_id', 'rooms.id')->limit(1),
        ])->orderBy('number')->orderBy('id')->paginate(50)->withQueryString()->through(function (Room $room) {
            $housekeeping = HousekeepingStatus::tryFrom($room->getAttribute('housekeeping_status') ?? '') ?? HousekeepingStatus::Dirty;
            [$status, $label] = match ($housekeeping) {
                HousekeepingStatus::Unavailable => ['out_of_order', __('Out of order')],
                HousekeepingStatus::Clean => ['available', __('Available')],
                default => [$housekeeping->value, $housekeeping->label()],
            };

            return [
                'id' => $room->id,
                'number' => $room->number,
                'roomType' => $room->roomType->name,
                'status' => $status,
                'statusLabel' => $label,
                'tone' => $housekeeping->tone(),
            ];
        });
    }
}
