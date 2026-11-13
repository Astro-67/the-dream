<?php

namespace App\Domains\Reservations;

use App\Domains\Billing\FolioStatement;
use App\Domains\Billing\FolioSummary;
use App\Domains\Billing\Models\Folio;
use App\Domains\FiscalReceipting\CheckoutReport;
use App\Domains\Reservations\Models\Reservation;
use Illuminate\Support\Collection;

final class GroupStatement
{
    /** @return array<string, mixed> */
    public static function of(Reservation $reservation): array
    {
        $members = app(BookingGroupMembers::class)->reservations($reservation);
        $rooms = $members->map(fn (Reservation $member) => [...FolioStatement::of($member), 'reports' => CheckoutReport::numbers($member)]);
        $folios = Folio::query()->whereIn('reservation_id', $members->modelKeys())->with('postings')->get();
        $totals = $folios->groupBy(fn (Folio $folio) => $folio->currency->value)->sortKeys()
            ->map(function (Collection $same, string $currency) use ($rooms) {
                $estimates = $rooms->pluck('totals')->flatten(1)->where('currency', $currency)->pluck('estimate')->filter();

                return [
                    'currency' => $currency, ...FolioSummary::of($same->flatMap(fn (Folio $folio) => $folio->postings)),
                    'estimate' => $estimates->isEmpty() ? null : ['nights' => (int) $estimates->sum('nights'), 'amount' => (int) $estimates->sum('amount')],
                ];
            })->values();

        return [
            'reservation' => $rooms->firstWhere('reservation.reservation_number', $reservation->reservation_number)['reservation'],
            'property' => $rooms->first()['property'],
            'payingInstitution' => $reservation->paying_institution_type === null ? null : [
                'name' => $reservation->paying_institution_name, 'zrbNumber' => $reservation->paying_institution_zrb_number,
            ],
            'group' => [
                'arrival' => $members->min('arrival')->toDateString(), 'departure' => $members->max('departure')->toDateString(),
                'rooms' => $members->count(), 'guests' => $members->sum(fn (Reservation $member) => $member->adults + $member->children),
            ],
            'rooms' => $rooms,
            'totals' => $totals,
            'zraDocuments' => CheckoutReport::overview($reservation),
        ];
    }
}
