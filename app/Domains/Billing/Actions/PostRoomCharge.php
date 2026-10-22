<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Rates\Enums\ChargeKind;
use App\Domains\Rates\TaxCalculator;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Money\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PostRoomCharge
{
    public function handle(?User $actor, Reservation $reservation, string $date): Posting
    {
        $post = fn () => DB::transaction(function () use ($actor, $reservation, $date) {
            $reservation = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $existing = Posting::query()->where('reservation_id', $reservation->id)->whereDate('business_date', $date)->where('category', 'room')->first();
            if ($existing !== null) {
                return $existing;
            }
            if ($reservation->status !== ReservationStatus::CheckedIn || $date < $reservation->arrival->toDateString()) {
                throw ValidationException::withMessages(['reservation' => __('Room charges require an in-house reservation on the business date.')]);
            }
            $line = $reservation->nightPrice($date);
            if ($line === null) {
                throw ValidationException::withMessages(['reservation' => __('This reservation has no price line for the business date.')]);
            }
            $currency = Currency::from($line->getAttribute('currency'));
            $folio = Folio::query()->where('reservation_id', $reservation->id)->where('currency', $currency->value)->lockForUpdate()->firstOrFail();
            $property = Property::query()->findOrFail($reservation->property_id);
            try {
                $tax = TaxCalculator::forProperty($property, $line->getAttribute('amount'), $currency, $date, $reservation->getAttribute('adults') + $reservation->getAttribute('children'), ChargeKind::Room);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['reservation' => __(':reservation: :reason', ['reservation' => $reservation->reservation_number, 'reason' => $e->getMessage()])]);
            }
            $posting = Posting::query()->create([
                'folio_id' => $folio->id, 'reservation_id' => $reservation->id, 'price_line_id' => $line->getKey(),
                'type' => 'charge', 'category' => 'room', 'quantity' => 1, 'unit_price' => $line->getAttribute('amount'),
                'net' => $tax->net, 'amount' => $tax->gross(), 'currency' => $currency,
                'tax_lines' => $tax->postedLines(), 'tax_rule_ids' => $tax->ruleIds,
                'idempotency_key' => "room:{$reservation->id}:{$date}", 'actor_id' => $actor?->id,
                'business_date' => $date, 'posted_at' => now(),
            ]);
            Audit::record($actor, 'created', $posting, 'Room charge posted', ['reservation_id' => $reservation->id, 'business_date' => $date]);

            return $posting;
        });

        // A system run (no actor) is audited by the night audit that called it.
        return $actor === null ? $post() : Audit::validationRefusal($actor, $reservation, 'Room charge refused', $post);
    }
}
