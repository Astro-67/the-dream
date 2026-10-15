<?php

namespace App\Domains\Reports;

use App\Domains\Tenancy\Models\Property;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
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
        ['folios' => $folios, 'reports' => $reports] = $properties->isEmpty() ? ['folios' => null, 'reports' => collect()] : Tenancy::within(
            TenantContext::forOrganization($properties->first()->organization_id),
            function () use ($properties, $from, $to): array {
                $kpis = $this->kpis->forProperties($properties, $from, $to);
                $operations = $this->operations->forProperties($properties, $from, $to);
                $finance = $this->finance->summariesForProperties($properties, $from, $to);
                $names = $properties->pluck('name', 'id');
                $folios = $this->finance->folios($properties, array_sum(array_column($finance, 'unsettledFolios')))->through(fn (array $folio): array => [...$folio, 'propertyName' => $names[$folio['propertyId']]]);

                return ['folios' => $folios, 'reports' => $properties->map(fn (Property $property): array => [
                    'property' => $property,
                    'kpis' => $kpis[$property->id],
                    'operations' => $operations[$property->id],
                    'finance' => $finance[$property->id],
                ])];
            },
        );
        // Every property's rows of one section (a dotted path), as one collection.
        $part = fn (string $path): Collection => collect(array_merge(...$reports->map(fn (array $report): array => data_get($report, $path))->all()));
        $payments = [];
        foreach ($reports as $report) {
            foreach ($report['finance']['payments']['rows'] as $row) {
                $payments[] = ['propertyId' => $report['property']->id, 'propertyName' => $report['property']->name, 'timezone' => $report['property']->timezone, ...$row];
            }
        }

        return [
            'properties' => $reports->map(fn (array $report): array => [
                'id' => $report['property']->id,
                'name' => $report['property']->name,
                'currency' => $report['property']->currency->value,
                'timezone' => $report['property']->timezone,
                'unsettledFolios' => $report['finance']['unsettledFolios'],
            ])->all(),
            'kpis' => $this->kpis($reports->pluck('kpis')),
            'operations' => [
                'daily' => self::addUp($part('operations.daily'), 'date', ['arrivals', 'departures', 'inHouse']),
                'housekeeping' => self::addUp($part('operations.housekeeping'), 'status', ['rooms']),
            ],
            'finance' => [
                'folios' => $folios,
                'openAfterDeparture' => self::sorted(self::addUp($part('finance.openAfterDeparture'), 'currency', ['folios', 'balance'])),
                'payments' => [
                    'rows' => $payments,
                    'totals' => self::sorted(self::addUp($part('finance.payments.totals'), 'currency', ['payments', 'refunds', 'reversals', 'net'])),
                ],
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
        $rows = [];
        foreach ($reports as $report) {
            foreach ($report['currencies'] as $row) {
                $rows[] = [...$row, 'sold' => $report['soldRoomNights'], 'sellable' => $report['sellableRoomNights']];
            }
        }
        $currencies = collect($rows)->groupBy('currency')->sortKeys()
            ->map(function (Collection $rows, string $currency): array {
                $amount = (int) $rows->sum('bookedRoomValue');
                $sold = (int) $rows->sum('sold');
                $sellable = (int) $rows->sum('sellable');

                return [
                    'currency' => $currency,
                    'bookedRoomValue' => $amount,
                    'soldRoomNights' => $sold,
                    'sellableRoomNights' => $sellable,
                    'bookedAdr' => PropertyKpis::ratio($amount, $sold),
                    'bookedRevpar' => PropertyKpis::ratio($amount, $sellable),
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

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function sorted(array $rows): array
    {
        return collect($rows)->sortBy('currency')->values()->all();
    }
}
