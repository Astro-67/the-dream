<?php

namespace App\Domains\Reservations;

use App\Domains\Housekeeping\RoomReadiness;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Models\RoomAssignmentNight;
use Illuminate\Database\Eloquent\Collection;

class AssignableRooms
{
    /** @return Collection<int, Room> */
    public function forStay(string $roomTypeId, string $arrival, string $departure, ?string $reservationId = null): Collection
    {
        return Room::query()->active()->where('room_type_id', $roomTypeId)
            ->whereDoesntHave('outOfServicePeriods', fn ($query) => $query->whereDate('starts_on', '<', $departure)->whereDate('returns_on', '>', $arrival))
            ->whereNotIn('id', RoomAssignmentNight::query()->select('room_id')->whereDate('date', '>=', $arrival)->whereDate('date', '<', $departure)->when($reservationId, fn ($query) => $query->where('reservation_id', '!=', $reservationId)))
            ->orderBy('number')->get(['id', 'number', 'property_id'])->each(function (Room $room) {
                $room->setAttribute('isReady', app(RoomReadiness::class)->isReady($room));
                $room->makeHidden('property_id');
            });
    }
}
