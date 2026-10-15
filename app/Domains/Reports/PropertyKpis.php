<?php

namespace App\Domains\Reports;

use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\ReservationPriceLine;
use App\Domains\Tenancy\Models\Property;
use Illuminate\Support\Collection;

final class PropertyKpis
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
        $inventory = InventoryRecord::query()->whereIn('property_id', $ids)->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->selectRaw('property_id, SUM(sold_count) as sold, SUM(sellable_count) as sellable')
            ->groupBy('property_id')->toBase()->get()->keyBy('property_id');
        $reservations = Reservation::query()->whereIn('property_id', $ids)
            ->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::CheckedIn, ReservationStatus::CheckedOut])
            ->select('id');
        $values = ReservationPriceLine::query()->whereIn('property_id', $ids)
            ->whereIn('reservation_id', $reservations)->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->selectRaw('property_id, currency, SUM(amount) as booked_value')->groupBy('property_id', 'currency')->orderBy('currency')
            ->toBase()->get()->mapToGroups(function (object $line) use ($inventory): array {
                $sold = (int) ($inventory->get($line->property_id)->sold ?? 0);
                $sellable = (int) ($inventory->get($line->property_id)->sellable ?? 0);
                $amount = (int) $line->booked_value;

                return [$line->property_id => [
                    'currency' => $line->currency,
                    'bookedRoomValue' => $amount,
                    'bookedAdr' => $sold === 0 ? null : intdiv($amount + intdiv($sold, 2), $sold),
                    'bookedRevpar' => $sellable === 0 ? null : intdiv($amount + intdiv($sellable, 2), $sellable),
                ]];
            });

        return $properties->mapWithKeys(function (Property $property) use ($inventory, $values): array {
            $sold = (int) ($inventory->get($property->id)->sold ?? 0);
            $sellable = (int) ($inventory->get($property->id)->sellable ?? 0);
            $currencies = $values->get($property->id, collect())->values()->all();

            return [$property->id => [
                'soldRoomNights' => $sold,
                'sellableRoomNights' => $sellable,
                'occupancy' => $sellable === 0 ? null : $sold / $sellable,
                'currencies' => $currencies ?: [[
                    'currency' => $property->currency->value,
                    'bookedRoomValue' => 0,
                    'bookedAdr' => $sold === 0 ? null : 0,
                    'bookedRevpar' => $sellable === 0 ? null : 0,
                ]],
            ]];
        })->all();
    }
}
