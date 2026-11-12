<?php

namespace App\Domains\Billing;

use App\Domains\Billing\Models\CustomerAccount;
use App\Domains\Billing\Models\FeeProposal;
use App\Domains\Billing\Models\Posting;
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
        // ZRA has no void call, so a receipted extra reversed in Karafuu stays on ZRA's books (spec #249).
        $reversals = Posting::query()->where('postings.property_id', $property->id)->where('postings.type', 'reversal')
            ->join('postings as original', 'original.id', '=', 'postings.related_posting_id')->where('original.property_id', $property->id)->whereNotNull('original.vfms_receipt_number')
            ->join('folios', 'folios.id', '=', 'original.folio_id')->where('folios.property_id', $property->id)
            ->orderBy('postings.posted_at')->get(['postings.reason', 'original.vfms_receipt_number as receipt', 'folios.reservation_id', 'folios.customer_account_id']);
        $numbers = Reservation::query()->where('property_id', $property->id)->whereIn('id', [...$fees->pluck('reservation_id'), ...$reversals->pluck('reservation_id')])->pluck('reservation_number', 'id');
        $accounts = CustomerAccount::query()->where('property_id', $property->id)->whereIn('id', $reversals->pluck('customer_account_id'))->pluck('number', 'id');

        return $stays->concat($fees->map(fn (FeeProposal $fee) => [
            'reservationNumber' => $numbers[$fee->reservation_id], 'kind' => $fee->kind === 'no_show' ? __('No-show fee') : __('Cancellation fee'), 'reason' => $fee->reason,
        ]))->concat($reversals->map(fn (Posting $reversal) => [
            'reservationNumber' => $reversal->getAttribute('customer_account_id') === null ? $numbers[$reversal->getAttribute('reservation_id')] : $accounts[$reversal->getAttribute('customer_account_id')],
            'kind' => __('Receipted extra reversed'),
            'reason' => __('ZRA receipt :number: :reason', ['number' => $reversal->getAttribute('receipt'), 'reason' => $reversal->reason]),
        ]))->values()->all();
    }
}
