<?php

namespace App\Domains\Reports;

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use Carbon\CarbonPeriod;

final class PropertyOperations
{
    /** @return array<string, mixed> */
    public function forProperty(Property $property, string $from, string $to): array
    {
        $property = Property::query()->real()->findOrFail($property->id);
        $daily = [];
        foreach (CarbonPeriod::create($from, $to) as $date) {
            $day = $date->toDateString();
            $daily[$day] = ['date' => $day, 'arrivals' => 0, 'departures' => 0, 'inHouse' => 0];
        }

        $reservations = Reservation::query()->where('property_id', $property->id)
            ->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::CheckedIn, ReservationStatus::CheckedOut])
            ->whereDate('arrival', '<=', $to)->whereDate('departure', '>=', $from)
            ->get(['arrival', 'departure', 'status']);

        foreach ($reservations as $reservation) {
            $arrival = $reservation->arrival->toDateString();
            $departure = $reservation->departure->toDateString();
            if (isset($daily[$arrival]) && $reservation->status !== ReservationStatus::CheckedOut) {
                $daily[$arrival]['arrivals']++;
            }
            if (isset($daily[$departure])) {
                $daily[$departure]['departures']++;
            }
            if ($reservation->status === ReservationStatus::CheckedIn) {
                $lastNight = min($to, $reservation->departure->subDay()->toDateString());
                foreach (CarbonPeriod::create(max($from, $arrival), $lastNight) as $night) {
                    $daily[$night->toDateString()]['inHouse']++;
                }
            }
        }

        $statuses = RoomStatus::query()->where('property_id', $property->id)
            ->whereIn('room_id', Room::query()->where('property_id', $property->id)->active()->select('id'))
            ->selectRaw('status, COUNT(*) as rooms')->groupBy('status')->pluck('rooms', 'status');

        return [
            'daily' => array_values($daily),
            'housekeeping' => array_map(fn (HousekeepingStatus $status): array => [
                'status' => $status->value,
                'label' => $status->label(),
                'tone' => $status->tone(),
                'rooms' => (int) ($statuses[$status->value] ?? 0),
            ], HousekeepingStatus::cases()),
        ];
    }
}
