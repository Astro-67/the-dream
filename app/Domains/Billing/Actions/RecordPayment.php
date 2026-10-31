<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\FolioSummary;
use App\Domains\Billing\Models\CashierShift;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Billing\Models\Receipt;
use App\Domains\Billing\Models\ReceiptSequence;
use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Records money received on a folio and issues its non-fiscal receipt. A payment
 * is stored with a negative amount, so it reduces the folio balance; one taken
 * before arrival is a deposit. When enabled, cash needs the recording cashier's open shift and counts towards it.
 * The same idempotency key on the same folio posts once and issues one receipt (FIN-03).
 */
class RecordPayment
{
    public const array METHODS = ['cash', 'bank_transfer', 'mobile_money', 'card', 'channel_collected', 'other'];

    /** @param array{method: string, amount?: int|null, currency: string, reference?: string|null, card_last_four?: string|null, card_brand?: string|null, idempotency_key?: string|null} $details */
    public function handle(?User $actor, Folio $folio, array $details): Posting
    {
        return Audit::validationRefusal($actor, $folio, 'Payment refused', fn () => DB::transaction(function () use ($actor, $folio, $details) {
            $reservation = $details['method'] === 'channel_collected'
                ? Reservation::query()->whereKey($folio->reservation_id)->lockForUpdate()->firstOrFail()
                : null;
            $folio = Folio::query()->whereKey($folio->id)->lockForUpdate()->firstOrFail();
            $key = $details['idempotency_key'] ?? (string) Str::ulid();
            $existing = Posting::query()->where('folio_id', $folio->id)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $existing;
            }
            $method = $details['method'];
            $property = Property::query()->whereKey($folio->property_id)
                ->when($method === 'cash', fn ($query) => $query->lockForUpdate())->firstOrFail();
            if (! in_array($method, self::METHODS, true) || ! in_array($method, $property->settings()['payment_settings']['accepted_methods'], true)) {
                throw ValidationException::withMessages(['method' => __('Choose a payment method accepted by this property.')]);
            }
            if ($details['currency'] !== $folio->currency->value) {
                throw ValidationException::withMessages(['currency' => __('This folio is in :currency.', ['currency' => $folio->currency->value])]);
            }
            $reservation ??= Reservation::query()->findOrFail($folio->reservation_id);
            $amount = $method === 'channel_collected' ? $this->channelCollected($reservation, $folio) : (int) ($details['amount'] ?? 0);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => __('Enter an amount above zero.')]);
            }
            // ADR 0004: a card keeps at most its masked last four digits and brand.
            $card = $method === 'card' ? ['card_last_four' => $details['card_last_four'] ?? null, 'card_brand' => $details['card_brand'] ?? null] : [];
            if ($card !== [] && $card['card_last_four'] !== null && preg_match('/^\d{4}$/', $card['card_last_four']) !== 1) {
                throw ValidationException::withMessages(['card_last_four' => __('Enter only the last four digits of the card.')]);
            }
            if ($method === 'cash' && $actor === null) {
                throw ValidationException::withMessages(['method' => __('A cashier must record a cash payment.')]);
            }
            $shift = $method === 'cash' && $property->settings()['payment_settings']['use_cashier_shifts']
                ? $this->openShift($actor, $property) : null;
            $date = BusinessDate::of($property);
            $posting = Posting::query()->create([
                'folio_id' => $folio->id, 'type' => 'payment',
                'category' => $reservation->status === ReservationStatus::Confirmed ? 'deposit' : 'payment',
                'method' => $method, 'cashier_shift_id' => $shift?->id, 'reference' => $details['reference'] ?? null, ...$card,
                'quantity' => 1, 'unit_price' => $amount, 'net' => $amount, 'amount' => -$amount, 'currency' => $folio->currency,
                'tax_lines' => [], 'tax_rule_ids' => [],
                'idempotency_key' => $key, 'actor_id' => $actor?->id, 'business_date' => $date, 'posted_at' => now(),
            ]);
            $receipt = $this->issueReceipt($property, $posting);
            Audit::record($actor, 'created', $posting, 'Payment recorded', ['folio_id' => $folio->id, 'method' => $method, 'amount' => $amount, 'currency' => $folio->currency->value, 'receipt' => $receipt->number]);

            return $posting->setRelation('receipt', $receipt);
        }, attempts: 3));
    }

    /** The cashier's open shift, locked so a closing count cannot miss this cash. */
    private function openShift(?User $actor, Property $property): CashierShift
    {
        // where(..., null) would match closed shifts, so a system actor never takes cash.
        return ($actor === null ? null : CashierShift::query()->where('property_id', $property->id)->where('open_user_id', $actor->id)->lockForUpdate()->first())
            ?? throw ValidationException::withMessages(['method' => __('Open a cashier shift before taking cash.')]);
    }

    private function channelCollected(Reservation $reservation, Folio $folio): int
    {
        $collected = $reservation->channel_collected_amount;
        if ($reservation->payment_collector !== 'channel' || $collected === null) {
            throw ValidationException::withMessages(['method' => __('This reservation has no channel-collected amount.')]);
        }
        $planCurrency = $reservation->priceLines()->value('currency');
        if ($planCurrency !== null && $planCurrency !== $folio->currency->value) {
            throw ValidationException::withMessages(['currency' => __('The channel collected :currency.', ['currency' => $planCurrency])]);
        }
        if (Posting::query()->whereIn('folio_id', Folio::query()->select('id')->where('reservation_id', $reservation->id))
            ->where('type', 'payment')->where('method', 'channel_collected')->exists()) {
            throw ValidationException::withMessages(['method' => __('The channel-collected amount is already posted.')]);
        }

        return $collected;
    }

    /** One gap-free sequence per property, taken under the sequence row lock and rolled back with the payment. */
    private function issueReceipt(Property $property, Posting $posting): Receipt
    {
        $sequence = ReceiptSequence::query()->where('property_id', $property->id)->lockForUpdate()->first();
        if ($sequence === null) {
            // The property lock serializes creating the first sequence row.
            Property::query()->whereKey($property->id)->lockForUpdate()->first();
            $sequence = ReceiptSequence::query()->where('property_id', $property->id)->lockForUpdate()->first()
                ?? ReceiptSequence::query()->create(['last_number' => 0]);
        }
        $sequence->increment('last_number');

        $reservation = Reservation::query()->findOrFail(Folio::query()->findOrFail($posting->folio_id)->reservation_id);
        $estimate = collect(FolioSummary::totals($reservation))->firstWhere('currency', $posting->currency->value)['estimate'] ?? null;

        // Taken under the folio lock, so it holds this payment and every posting committed before it.
        $summary = [
            'payment_posting_id' => $posting->id, 'currency' => $posting->currency->value, ...FolioSummary::of(Posting::query()->where('folio_id', $posting->folio_id)->get()),
            'estimate' => $estimate === null ? null : [...$estimate, 'currency' => $posting->currency->value],
            // The stay as it was then, so a later room move or date change does not rewrite this receipt.
            'stay' => $reservation->stay(),
        ];

        return Receipt::query()->create([
            'posting_id' => $posting->id, 'sequence' => $sequence->last_number,
            'number' => $property->receipt_prefix.str_pad((string) $sequence->last_number, 6, '0', STR_PAD_LEFT),
            'issued_at' => now(), 'summary' => $summary,
        ]);
    }
}
