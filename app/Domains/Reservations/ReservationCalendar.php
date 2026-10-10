<?php

namespace App\Domains\Reservations;

use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Inventory\Models\Room;
use App\Domains\Rates\DateRange;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use Illuminate\Support\Collection;

class ReservationCalendar
{
    /**
     * @param  list<string>  $roomTypeIds
     * @return Collection<string, array<string, mixed>>
     */
    public function forRange(array $roomTypeIds, string $from, string $to): Collection
    {
        $reservations = Reservation::query()->whereIn('room_type_id', $roomTypeIds)
            ->whereNotIn('status', [ReservationStatus::Cancelled, ReservationStatus::Void])
            ->whereDate('arrival', '<=', $to)->whereDate('departure', '>', $from)
            ->orderBy('arrival')->orderBy('reservation_number')->get();
        $links = ReservationGuest::query()->whereIn('reservation_id', $reservations->modelKeys())->orderBy('id')->get();
        $names = Guest::query()->whereIn('id', $links->pluck('guest_id'))->pluck('name', 'id');
        $guests = $links->groupBy('reservation_id')->map(fn ($links) => $links->map(fn ($link) => $names->get($link->getAttribute('guest_id')))->filter()->implode(', '));
        $assigned = $reservations->groupBy('room_id');

        $rooms = Room::query()->whereIn('room_type_id', $roomTypeIds)->orderBy('number')->get()
            ->groupBy('room_type_id')->map(fn ($rooms) => $rooms->map(fn (Room $room) => [
                'id' => $room->id, 'number' => $room->number,
                'reservations' => $assigned->get($room->id, collect())->map(fn (Reservation $reservation) => [
                    'reservation_number' => $reservation->reservation_number,
                    'arrival' => $reservation->arrival->toDateString(), 'departure' => $reservation->departure->toDateString(),
                    'guest_name' => $guests->get($reservation->id, __('Guest not recorded')),
                    'source' => $reservation->getAttribute('source'), 'status' => $reservation->status->value,
                    'url' => route('reservations.show', $reservation),
                ])->values(),
            ])->values());

        $unassigned = $reservations->whereNull('room_id')->groupBy('room_type_id');
        $dates = DateRange::dates($from, $to);

        return collect($roomTypeIds)->mapWithKeys(fn (string $id) => [$id => [
            'rooms' => $rooms->get($id, collect()),
            'unassigned' => collect($dates)->mapWithKeys(fn (string $date) => [$date => $unassigned->get($id, collect())->filter(fn (Reservation $reservation) => $reservation->arrival->toDateString() <= $date && $reservation->departure->toDateString() > $date)->count()])->all(),
        ]]);
    }
}
