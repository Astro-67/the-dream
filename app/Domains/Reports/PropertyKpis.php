<?php

namespace App\Domains\Reports;

use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\ReservationPriceLine;
use App\Domains\Tenancy\Models\Property;

final class PropertyKpis
{
    /** @return array<string, mixed> */
    public function forProperty(Property $property, string $from, string $to): array
    {
        $inventory = InventoryRecord::query()->where('property_id', $property->id)->whereDate('date', '>=', $from)->whereDate('date', '<=', $to);
        $sold = (int) (clone $inventory)->sum('sold_count');
        $sellable = (int) $inventory->sum('sellable_count');
        $reservations = Reservation::query()->where('property_id', $property->id)
            ->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::CheckedIn, ReservationStatus::CheckedOut])
            ->select('id');
        $currencies = ReservationPriceLine::query()->where('property_id', $property->id)
            ->whereIn('reservation_id', $reservations)->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->selectRaw('currency, SUM(amount) as booked_value')->groupBy('currency')->orderBy('currency')->get()
            ->map(function (ReservationPriceLine $line) use ($sold, $sellable): array {
                $amount = (int) $line->getAttribute('booked_value');

                return [
                    'currency' => $line->getAttribute('currency'),
                    'bookedRoomValue' => $amount,
                    'bookedAdr' => $sold === 0 ? null : intdiv($amount + intdiv($sold, 2), $sold),
                    'bookedRevpar' => $sellable === 0 ? null : intdiv($amount + intdiv($sellable, 2), $sellable),
                ];
            })->all();

        return [
            'soldRoomNights' => $sold,
            'sellableRoomNights' => $sellable,
            'occupancy' => $sellable === 0 ? null : $sold / $sellable,
            'currencies' => $currencies ?: [[
                'currency' => $property->currency->value,
                'bookedRoomValue' => 0,
                'bookedAdr' => $sold === 0 ? null : 0,
                'bookedRevpar' => $sellable === 0 ? null : 0,
            ]],
        ];
    }
}
