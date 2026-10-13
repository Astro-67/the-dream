<?php

namespace App\Http\Controllers\Web\Billing;

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Receipt;
use App\Domains\Guests\Models\Booker;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ReceiptController extends Controller
{
    public function show(Receipt $receipt): Response
    {
        Gate::authorize('view', $receipt);
        $payment = $receipt->posting()->firstOrFail();
        $reservation = Reservation::query()->findOrFail(Folio::query()->findOrFail($payment->folio_id)->reservation_id);
        $property = Property::query()->findOrFail($receipt->getAttribute('property_id'));

        return Inertia::render('receipts/show', [
            'receipt' => [
                'id' => $receipt->id, 'number' => $receipt->number, 'label' => Receipt::LABEL, 'vfms_receipt_number' => $receipt->vfms_receipt_number,
                'issued_at' => $receipt->issued_at->toIso8601String(),
                'amount' => -$payment->amount, 'currency' => $payment->currency->value, 'deposit' => $payment->category === 'deposit',
                'method' => $payment->method, 'reference' => $payment->reference, 'card_last_four' => $payment->card_last_four, 'card_brand' => $payment->card_brand,
            ],
            'reservation' => ['reservation_number' => $reservation->reservation_number, 'payment_collector' => $reservation->payment_collector],
            'booker' => Booker::query()->findOrFail($reservation->getAttribute('booker_id'))->only(['name']),
            'property' => $property->only(['name', 'address_line', 'city', 'phone', 'email']),
        ]);
    }
}
