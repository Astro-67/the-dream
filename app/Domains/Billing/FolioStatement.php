<?php

namespace App\Domains\Billing;

use App\Domains\Billing\Models\Posting;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;

final class FolioStatement
{
    /** @return array<string, mixed> */
    public static function of(Reservation $reservation): array
    {
        $property = Property::query()->findOrFail($reservation->property_id);
        $folios = FolioSummary::folios($reservation);

        return [
            'reservation' => [...$reservation->stay(), ...$reservation->only(['adults', 'children'])],
            'folios' => $folios->map(fn (array $folio, int $index) => [
                'number' => $index + 1, 'currency' => $folio['folio']->currency->value, 'summary' => $folio['summary'],
                'postings' => $folio['postings']->map(fn (Posting $posting) => [
                    ...$posting->only(['id', 'type', 'category', 'description', 'quantity', 'unit_price', 'amount', 'method']),
                    'business_date' => $posting->business_date->toDateString(),
                    'tax_lines' => array_map(fn (array $line) => ['name' => $line['name'], 'inclusive' => $line['inclusive'], 'amount' => $line['amount'], 'unavailable' => $line['unavailable'] ?? false], $posting->tax_lines),
                ])->values(),
            ])->values(),
            'totals' => FolioSummary::totals($reservation, $folios),
            'taxSource' => FolioSummary::taxSource($reservation, $property),
            'property' => $property->only(['name', 'address_line', 'city', 'phone', 'email']),
        ];
    }
}
