<?php

namespace App\Domains\Reports;

use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\ReservationPriceLine;
use App\Domains\Tenancy\Models\Property;
use Carbon\CarbonImmutable;
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
        $until = CarbonImmutable::parse($to)->addDay()->toDateString();
        $inventory = InventoryRecord::query()->whereIn('property_id', $ids)->where('date', '>=', $from)->where('date', '<', $until)
            ->selectRaw('property_id, SUM(sold_count) as sold, SUM(sellable_count) as sellable')
            ->groupBy('property_id')->toBase()->get()->keyBy('property_id');
        $prices = ReservationPriceLine::query()->whereIn('property_id', $ids)
            ->where('date', '>=', $from)->where('date', '<', $until)
            ->selectRaw('property_id, reservation_id, currency, SUM(amount) as booked_value')
            ->groupBy('property_id', 'reservation_id', 'currency');
        $values = Reservation::query()->whereIn('reservations.property_id', $ids)
            ->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::CheckedIn, ReservationStatus::CheckedOut])
            ->joinSub($prices, 'prices', function ($join): void {
                $join->on('prices.reservation_id', '=', 'reservations.id')->on('prices.property_id', '=', 'reservations.property_id');
            })
            ->selectRaw('reservations.property_id, prices.currency, SUM(prices.booked_value) as booked_value')
            ->groupBy('reservations.property_id', 'prices.currency')->orderBy('prices.currency')
            ->toBase()->get()->mapToGroups(function (object $line) use ($inventory): array {
                $sold = (int) ($inventory->get($line->property_id)->sold ?? 0);
                $sellable = (int) ($inventory->get($line->property_id)->sellable ?? 0);
                $amount = (int) $line->booked_value;

                return [$line->property_id => [
                    'currency' => $line->currency,
                    'bookedRoomValue' => $amount,
                    'bookedAdr' => self::ratio($amount, $sold),
                    'bookedRevpar' => self::ratio($amount, $sellable),
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
                    'bookedAdr' => self::ratio(0, $sold),
                    'bookedRevpar' => self::ratio(0, $sellable),
                ]],
            ]];
        })->all();
    }

    /** Minor units per room-night, rounded half up; null for a zero denominator. */
    public static function ratio(int $amount, int $roomNights): ?int
    {
        return $roomNights === 0 ? null : intdiv($amount + intdiv($roomNights, 2), $roomNights);
    }
}
