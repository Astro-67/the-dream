<?php

namespace App\Domains\Reports;

use App\Domains\Tenancy\Models\Property;
use Illuminate\Support\Collection;

/**
 * The property reports added up across several real properties of one organization.
 * Counts and money are added; ratios are recomputed from the added numerators and
 * denominators, never averaged. Money stays grouped by currency, and each currency's
 * ADR and RevPAR divide by the room-nights of the properties that report it.
 * The caller authorizes every property; demo properties are left out here.
 */
final class GroupReports
{
    public function __construct(
        private readonly PropertyKpis $kpis,
        private readonly PropertyOperations $operations,
        private readonly PropertyFinance $finance,
    ) {}

    /**
     * @param  Collection<int, Property>  $properties
     * @return array<string, mixed>
     */
    public function forProperties(Collection $properties, string $from, string $to): array
    {
        $properties = Property::query()->real()->whereKey($properties->pluck('id'))->orderBy('name')->orderBy('id')->get();
        $reports = $properties->map(fn (Property $property): array => [
            'property' => $property,
            'kpis' => $this->kpis->forProperty($property, $from, $to),
            'operations' => $this->operations->forProperty($property, $from, $to),
        ]);

        return [
            'kpis' => $this->kpis($reports->pluck('kpis')),
            'operations' => [
                'daily' => self::addUp($reports->flatMap(fn (array $report) => $report['operations']['daily']), 'date', ['arrivals', 'departures', 'inHouse']),
                'housekeeping' => self::addUp($reports->flatMap(fn (array $report) => $report['operations']['housekeeping']), 'status', ['rooms']),
            ],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $reports
     * @return array<string, mixed>
     */
    private function kpis(Collection $reports): array
    {
        $sold = (int) $reports->sum('soldRoomNights');
        $sellable = (int) $reports->sum('sellableRoomNights');
        $currencies = $reports
            ->flatMap(fn (array $report) => array_map(fn (array $row) => [...$row, 'sold' => $report['soldRoomNights'], 'sellable' => $report['sellableRoomNights']], $report['currencies']))
            ->groupBy('currency')->sortKeys()
            ->map(function (Collection $rows, string $currency): array {
                $amount = (int) $rows->sum('bookedRoomValue');
                $sold = (int) $rows->sum('sold');
                $sellable = (int) $rows->sum('sellable');

                return [
                    'currency' => $currency,
                    'bookedRoomValue' => $amount,
                    'soldRoomNights' => $sold,
                    'sellableRoomNights' => $sellable,
                    'bookedAdr' => self::ratio($amount, $sold),
                    'bookedRevpar' => self::ratio($amount, $sellable),
                ];
            })->values()->all();

        return [
            'soldRoomNights' => $sold,
            'sellableRoomNights' => $sellable,
            'occupancy' => $sellable === 0 ? null : $sold / $sellable,
            'currencies' => $currencies,
        ];
    }

    /**
     * Rows with the same $key merged in first-seen order, their $counts added.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<string>  $counts
     * @return list<array<string, mixed>>
     */
    private static function addUp(Collection $rows, string $key, array $counts): array
    {
        return $rows->groupBy($key)->map(fn (Collection $group): array => [
            ...$group->first(),
            ...array_combine($counts, array_map(fn (string $count): int => (int) $group->sum($count), $counts)),
        ])->values()->all();
    }

    /** Minor units rounded half up, as PropertyKpis rounds; null for a zero denominator. */
    private static function ratio(int $amount, int $denominator): ?int
    {
        return $denominator === 0 ? null : intdiv($amount + intdiv($denominator, 2), $denominator);
    }
}
