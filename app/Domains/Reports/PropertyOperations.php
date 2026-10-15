<?php

namespace App\Domains\Reports;

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

final class PropertyOperations
{
    /** @return array<string, mixed> */
    public function forProperty(Property $property, string $from, string $to): array
    {
        $property = Property::query()->real()->findOrFail($property->id);

        return $this->forProperties(collect([$property]), $from, $to)[$property->id];
    }

    /**
     * @param  Collection<int, Property>  $properties
     * @return array<string, array<string, mixed>>
     */
    public function forProperties(Collection $properties, string $from, string $to): array
    {
        $properties = Property::query()->real()->whereKey($properties->pluck('id'))->get();
        $ids = $properties->pluck('id');
        $reservations = Reservation::query()->whereIn('property_id', $ids)
            ->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::CheckedIn, ReservationStatus::CheckedOut])
            ->whereDate('arrival', '<=', $to)->whereDate('departure', '>=', $from)
            ->selectRaw('property_id, arrival, departure, status, COUNT(*) as reservations')
            ->groupBy('property_id', 'arrival', 'departure', 'status')->toBase()->get()->groupBy('property_id');
        $statuses = RoomStatus::query()->whereIn('property_id', $ids)
            ->whereIn('room_id', Room::query()->whereIn('property_id', $ids)->active()->select('id'))
            ->selectRaw('property_id, status, COUNT(*) as rooms')->groupBy('property_id', 'status')->toBase()->get()->groupBy('property_id');

        return $properties->mapWithKeys(function (Property $property) use ($from, $to, $reservations, $statuses): array {
            $daily = [];
            foreach (CarbonPeriod::create($from, $to) as $date) {
                $day = $date->toDateString();
                $daily[$day] = ['date' => $day, 'arrivals' => 0, 'departures' => 0, 'inHouse' => 0];
            }

            foreach ($reservations->get($property->id, collect()) as $reservation) {
                $arrival = substr($reservation->arrival, 0, 10);
                $departure = substr($reservation->departure, 0, 10);
                $count = (int) $reservation->reservations;
                if (isset($daily[$arrival])) {
                    $daily[$arrival]['arrivals'] += $count;
                }
                if (isset($daily[$departure])) {
                    $daily[$departure]['departures'] += $count;
                }
                // A checked-out stay keeps its nights: early departure and extension rewrite
                // its departure to the actual date before check-out, so its dates are the stay.
                if ($reservation->status !== ReservationStatus::Confirmed->value) {
                    foreach ($daily as $day => &$counts) {
                        if ($day >= $arrival && $day < $departure) {
                            $counts['inHouse'] += $count;
                        }
                    }
                    unset($counts);
                }
            }
            $rooms = $statuses->get($property->id, collect())->pluck('rooms', 'status');

            return [$property->id => [
                'daily' => array_values($daily),
                'housekeeping' => array_map(fn (HousekeepingStatus $status): array => [
                    'status' => $status->value,
                    'label' => $status->label(),
                    'tone' => $status->tone(),
                    'rooms' => (int) ($rooms[$status->value] ?? 0),
                ], HousekeepingStatus::cases()),
            ]];
        })->all();
    }
}
