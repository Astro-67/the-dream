<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Rates\Enums\ChargeKind;
use App\Domains\Rates\TaxCalculator;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Posts a VAT-inclusive extra to a folio, with the property's tax
 * rules in force today frozen on it. The same idempotency key on the same folio
 * posts once and returns the first posting (FIN-03).
 */
class PostExtraCharge
{
    /** Extras staff may post; an allowlist, so reserved posting categories (room, deposit, payment, refund, write_off) cannot skew folio totals. */
    public const array CATEGORIES = ['restaurant', 'bar', 'minibar', 'laundry', 'spa', 'transport', 'other'];

    /** @param array{category?: string, description?: string|null, quantity: int, unit_price: int, idempotency_key?: string|null} $details */
    public function handle(?User $actor, Folio $folio, array $details): Posting
    {
        return Audit::validationRefusal($actor, $folio, 'Extra charge refused', fn () => DB::transaction(function () use ($actor, $folio, $details) {
            $folio = Folio::query()->whereKey($folio->id)->lockForUpdate()->firstOrFail();
            // Two identical extras can both be real, so without a client key each call is a new submission.
            $key = $details['idempotency_key'] ?? (string) Str::ulid();
            $existing = Posting::query()->where('folio_id', $folio->id)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $existing;
            }
            $reservation = Reservation::query()->findOrFail($folio->reservation_id);
            $property = Property::query()->findOrFail($folio->property_id);
            $date = BusinessDate::of($property);
            $gross = $details['quantity'] * $details['unit_price'];
            try {
                $breakdown = TaxCalculator::forProperty($property, $gross, $folio->currency, $date, $reservation->getAttribute('adults') + $reservation->getAttribute('children'), ChargeKind::Extra, inclusivePrice: true);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['unit_price' => $e->getMessage()]);
            }
            $posting = Posting::query()->create([
                'folio_id' => $folio->id, 'type' => 'charge', 'category' => $details['category'] ?? 'other', 'description' => $details['description'] ?? null,
                'quantity' => $details['quantity'], 'unit_price' => $details['unit_price'],
                'net' => $breakdown->net, 'amount' => $breakdown->gross(), 'currency' => $folio->currency,
                'tax_lines' => $breakdown->postedLines(), 'tax_rule_ids' => $breakdown->ruleIds,
                'idempotency_key' => $key, 'actor_id' => $actor?->id, 'business_date' => $date, 'posted_at' => now(),
            ]);
            Audit::record($actor, 'created', $posting, 'Extra charge posted', ['folio_id' => $folio->id, 'amount' => $posting->amount, 'currency' => $folio->currency->value]);

            return $posting;
        }));
    }
}
