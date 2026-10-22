<?php

namespace App\Domains\Billing;

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Billing\Models\Receipt;
use App\Domains\Guests\Models\Booker;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;

/**
 * Reads one receipt for its page: the payment, the folio summary and stay stored when it
 * was taken, and later corrections, which link here but never change it.
 */
final class ReceiptView
{
    /** @return array<string, mixed> */
    public static function of(Receipt $receipt): array
    {
        $payment = $receipt->posting()->firstOrFail();
        $reservation = Reservation::query()->findOrFail(Folio::query()->findOrFail($payment->folio_id)->reservation_id);
        $summary = $receipt->summary;

        return [
            'receipt' => [
                'id' => $receipt->id, 'number' => $receipt->number, 'label' => __(Receipt::LABEL), 'vfms_receipt_number' => $receipt->vfms_receipt_number,
                'issued_at' => $receipt->issued_at->toIso8601String(),
                'amount' => -$payment->amount, 'currency' => $payment->currency->value, 'deposit' => $payment->category === 'deposit',
                'method' => $payment->method, 'reference' => $payment->reference, 'card_last_four' => $payment->card_last_four, 'card_brand' => $payment->card_brand,
                'cashier' => User::query()->find($payment->actor_id)?->name,
            ],
            // Never recomputed from today's folio: an old receipt without a snapshot says so instead.
            'summary' => $summary === null ? null : array_diff_key($summary, ['stay' => true]),
            'stay' => $summary['stay'] ?? $reservation->stay(),
            'corrections' => Posting::query()->where('related_posting_id', $payment->id)->orderBy('posted_at')->orderBy('id')->get(['id', 'type', 'amount', 'business_date'])
                ->map(fn (Posting $posting) => ['id' => $posting->id, 'type' => $posting->type, 'amount' => $posting->amount, 'business_date' => $posting->business_date->toDateString()])->all(),
            'reservation' => ['reservation_number' => $reservation->reservation_number, 'payment_collector' => $reservation->payment_collector],
            // Who paid: the booker when there is one, otherwise the guest.
            'booker' => ['name' => Booker::query()->find($reservation->getAttribute('booker_id'))?->getAttribute('name') ?? $reservation->leadGuest()->name ?? __('Guest')],
            'property' => Property::query()->findOrFail($receipt->getAttribute('property_id'))->only(['name', 'address_line', 'city', 'phone', 'email']),
        ];
    }
}
