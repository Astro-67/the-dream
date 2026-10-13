<?php

namespace App\Domains\Reservations;

use App\Domains\Housekeeping\RoomReadiness;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\RoomAssignmentNight;
use App\Domains\Tenancy\Models\Property;
use Illuminate\Database\Eloquent\Collection;

class AssignableRooms
{
    /** @return Collection<int, Room> */
    public function forStay(string $roomTypeId, string $arrival, string $departure, ?string $reservationId = null): Collection
    {
        return Room::query()->active()->where('room_type_id', $roomTypeId)
            ->whereDoesntHave('outOfServicePeriods', fn ($query) => $query->whereDate('starts_on', '<', $departure)->whereDate('returns_on', '>', $arrival))
            ->whereNotIn('id', RoomAssignmentNight::query()->select('room_id')->whereDate('date', '>=', $arrival)->whereDate('date', '<', $departure)->when($reservationId, fn ($query) => $query->where('reservation_id', '!=', $reservationId)))
            ->orderBy('number')->get(['id', 'number', 'property_id'])->each(fn (Room $room) => $this->shapeOption($room));
    }

    /**
     * Rooms of any type an in-house guest could move to for the remaining nights.
     *
     * @return list<array{id: string, number: string, roomType: string, isReady: bool}>
     */
    public function forMove(Reservation $reservation): array
    {
        $readiness = app(RoomReadiness::class);
        $from = max($readiness->businessDate(Property::query()->findOrFail($reservation->property_id)), $reservation->arrival->toDateString());
        $to = $reservation->departure->toDateString();

        return Room::query()->active()->with('roomType:id,name')->whereKeyNot($reservation->room_id)
            ->whereDoesntHave('outOfServicePeriods', fn ($query) => $query->whereDate('starts_on', '<', $to)->whereDate('returns_on', '>', $from))
            ->whereNotIn('id', RoomAssignmentNight::query()->select('room_id')->whereDate('date', '>=', $from)->whereDate('date', '<', $to)->where('reservation_id', '!=', $reservation->id))
            ->orderBy('number')->get()
            ->map(fn (Room $room) => ['id' => $room->id, 'number' => (string) $room->getAttribute('number'), 'roomType' => (string) $room->roomType?->name, 'isReady' => $readiness->isReady($room)])->all();
    }

    public function shapeOption(Room $room): void
    {
        $room->setAttribute('isReady', app(RoomReadiness::class)->isReady($room));
        $room->makeHidden('property_id');
    }
}
