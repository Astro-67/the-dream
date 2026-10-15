<?php

namespace App\Domains\Reports;

use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\ReservationLifecycleEvent;
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
        $events = ReservationLifecycleEvent::query()->whereIn('property_id', $ids)
            ->whereIn('type', ['checked_in', 'checked_out'])
            ->selectRaw("reservation_id, property_id, MIN(CASE WHEN type = 'checked_in' THEN DATE(business_date) END) as checked_in_date, MAX(CASE WHEN type = 'checked_out' THEN DATE(business_date) END) as checked_out_date")
            ->groupBy('property_id', 'reservation_id');
        $reservations = Reservation::query()->whereIn('reservations.property_id', $ids)
            ->leftJoinSub($events, 'history', function ($join): void {
                $join->on('history.reservation_id', '=', 'reservations.id')
                    ->on('history.property_id', '=', 'reservations.property_id');
            })
            ->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::CheckedIn, ReservationStatus::CheckedOut])
            ->whereRaw('COALESCE(history.checked_in_date, DATE(arrival)) <= ?', [$to])
            ->where(function ($query) use ($from): void {
                $query->whereRaw('COALESCE(history.checked_out_date, DATE(departure)) >= ?', [$from])
                    ->orWhere('status', ReservationStatus::CheckedIn);
            })
            ->selectRaw('reservations.property_id, arrival, departure, status, checked_in_date, checked_out_date, COUNT(*) as reservations')
            ->groupBy('reservations.property_id', 'arrival', 'departure', 'status', 'checked_in_date', 'checked_out_date')->toBase()->get()->groupBy('property_id');
        $statuses = RoomStatus::query()->whereIn('property_id', $ids)
            ->whereIn('room_id', Room::query()->whereIn('property_id', $ids)->active()->select('id'))
            ->selectRaw('property_id, status, COUNT(*) as rooms')->groupBy('property_id', 'status')->toBase()->get()->groupBy('property_id');

        return $properties->mapWithKeys(function (Property $property) use ($from, $to, $reservations, $statuses): array {
            $daily = [];
            $historyUnavailable = 0;
            $businessDate = BusinessDate::of($property);
            foreach (CarbonPeriod::create($from, $to) as $date) {
                $day = $date->toDateString();
                $daily[$day] = ['date' => $day, 'arrivals' => 0, 'departures' => 0, 'inHouse' => 0, 'plannedArrivals' => 0, 'plannedDepartures' => 0];
            }

            foreach ($reservations->get($property->id, collect()) as $reservation) {
                $arrival = substr($reservation->arrival, 0, 10);
                $departure = substr($reservation->departure, 0, 10);
                $checkedIn = $reservation->checked_in_date;
                $checkedOut = $reservation->checked_out_date;
                $count = (int) $reservation->reservations;
                if ($checkedIn !== null && isset($daily[$checkedIn])) {
                    $daily[$checkedIn]['arrivals'] += $count;
                }
                if ($checkedOut !== null && isset($daily[$checkedOut])) {
                    $daily[$checkedOut]['departures'] += $count;
                }
                if ($reservation->status === ReservationStatus::Confirmed->value && $arrival >= $businessDate && isset($daily[$arrival])) {
                    $daily[$arrival]['plannedArrivals'] += $count;
                }
                if ($reservation->status !== ReservationStatus::CheckedOut->value && $departure >= $businessDate && isset($daily[$departure])) {
                    $daily[$departure]['plannedDepartures'] += $count;
                }
                if ($reservation->status !== ReservationStatus::Confirmed->value && ($checkedIn === null || ($reservation->status === ReservationStatus::CheckedOut->value && $checkedOut === null))) {
                    $historyUnavailable += $count;
                }
                if ($checkedIn !== null && ($checkedOut !== null || $reservation->status === ReservationStatus::CheckedIn->value)) {
                    foreach ($daily as $day => &$counts) {
                        if ($day >= $checkedIn && $day <= $businessDate && ($checkedOut === null || $day < $checkedOut)) {
                            $counts['inHouse'] += $count;
                        }
                    }
                    unset($counts);
                }
            }
            $rooms = $statuses->get($property->id, collect())->pluck('rooms', 'status');

            return [$property->id => [
                'daily' => array_values($daily),
                'historyUnavailable' => $historyUnavailable,
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
