<?php

namespace App\Domains\Billing;

use App\Domains\Billing\Models\FeeProposal;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;

final class FiscalExceptions
{
    /** @return list<array{reservationNumber: string, kind: string, reason: string|null}> */
    public static function of(Property $property): array
    {
        $stays = Reservation::query()->where('property_id', $property->id)->whereNotNull('fiscal_exception_reason')->orderBy('reservation_number')->get()
            ->map(fn (Reservation $stay) => ['reservationNumber' => $stay->reservation_number, 'kind' => __('Close without filing'), 'reason' => $stay->getAttribute('fiscal_exception_reason')]);
        $fees = FeeProposal::query()->where('property_id', $property->id)->where('fiscal_exception_candidate', true)->where('status', 'posted')->orderBy('resolved_at')->get();
        $numbers = Reservation::query()->where('property_id', $property->id)->whereIn('id', $fees->pluck('reservation_id'))->pluck('reservation_number', 'id');

        return $stays->concat($fees->map(fn (FeeProposal $fee) => [
            'reservationNumber' => $numbers[$fee->reservation_id], 'kind' => $fee->kind === 'no_show' ? __('No-show fee') : __('Cancellation fee'), 'reason' => $fee->reason,
        ]))->values()->all();
    }
}
