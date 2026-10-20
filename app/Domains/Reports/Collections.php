<?php

namespace App\Domains\Reports;

use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Tenancy\Models\Property;
use Carbon\CarbonImmutable;

final class Collections
{
    /** @return array{from: string, to: string} */
    public function range(Property $property, string $period): array
    {
        $date = CarbonImmutable::parse(BusinessDate::of($property));
        [$from, $to] = match ($period) {
            'this-month' => [$date->startOfMonth(), $date->endOfMonth()],
            'last-month' => [$date->subMonthNoOverflow()->startOfMonth(), $date->subMonthNoOverflow()->endOfMonth()],
            'this-year' => [$date->startOfYear(), $date->endOfYear()],
            default => [$date, $date],
        };

        return ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    }

    /** @return array<string, mixed> */
    public function received(Property $property, string $from, string $to): array
    {
        $payments = app(PropertyFinance::class)->payments(collect([$property]), $from, $to)[$property->id] ?? ['rows' => [], 'totals' => []];
        $payments['rows'] = collect($payments['rows'])->groupBy(fn (array $row): string => $row['method'].'/'.$row['currency'])
            ->map(fn ($rows): array => [
                'method' => $rows->first()['method'],
                'currency' => $rows->first()['currency'],
                ...collect(['payments', 'refunds', 'reversals', 'net'])->mapWithKeys(fn (string $key): array => [$key => (int) $rows->sum($key)])->all(),
            ])->values()->all();

        return $payments;
    }

    /** @return array<string, mixed> */
    public function card(Property $property, string $period): array
    {
        $range = $this->range($property, $period);

        return [
            'period' => $period,
            ...$range,
            'received' => $this->received($property, $range['from'], $range['to']),
            'href' => route('payments.index', ['period' => $period]),
        ];
    }
}
