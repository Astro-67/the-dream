<?php

namespace App\Domains\Reports;

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Tenancy\Models\Property;

/**
 * Finance reports for one property: a snapshot of folio balances now, and payments
 * over a business-date range. Amounts stay in minor units of their own currency.
 */
final class PropertyFinance
{
    /** @return array<string, mixed> */
    public function forProperty(Property $property, string $from, string $to): array
    {
        $property = Property::query()->real()->findOrFail($property->id);
        $balance = Posting::query()->selectRaw('coalesce(sum(amount), 0)')->whereColumn('postings.folio_id', 'folios.id');
        $folios = Folio::query()->where('folios.property_id', $property->id)
            ->join('reservations', 'reservations.id', '=', 'folios.reservation_id')
            ->whereRaw('('.$balance->toSql().') <> 0', $balance->getBindings())
            ->select(['folios.id', 'folios.currency', 'reservations.reservation_number', 'reservations.status', 'reservations.departure'])
            ->selectSub($balance, 'balance')
            ->orderBy('reservations.departure')->orderBy('reservations.reservation_number')->orderBy('folios.currency')
            ->get()
            ->map(fn (Folio $folio): array => [
                'id' => $folio->id,
                'reservationNumber' => $folio->getAttribute('reservation_number'),
                'status' => $folio->getAttribute('status'),
                'departure' => substr((string) $folio->getAttribute('departure'), 0, 10),
                'departed' => $folio->getAttribute('status') === ReservationStatus::CheckedOut->value,
                'currency' => $folio->currency->value,
                'balance' => (int) $folio->getAttribute('balance'),
            ])->all();

        $openAfterDeparture = collect($folios)->where('departed', true)->groupBy('currency')->sortKeys()
            ->map(fn ($rows, string $currency): array => ['currency' => $currency, 'folios' => $rows->count(), 'balance' => $rows->sum('balance')])
            ->values()->all();

        return ['folios' => $folios, 'openAfterDeparture' => $openAfterDeparture];
    }
}
