<?php

namespace App\Domains\Reservations\Actions;

use App\Domains\Billing\Models\Folio;
use App\Domains\Guests\Models\Booker;
use App\Domains\Inventory\Actions\TakeInventory;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Rates\StayPricing;
use App\Domains\Rates\StayRestrictions;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\ReservationNumberSequence;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateReservation
{
    /** @param array<string, mixed> $details validated booking fields */
    public function handle(?User $actor, array $details): Reservation
    {
        return Audit::validationRefusal($actor, new Reservation, 'Reservation booking refused', fn () => DB::transaction(function () use ($actor, $details) {
            $type = RoomType::query()->findOrFail($details['room_type_id']);
            $plan = RatePlan::query()->whereNull('deactivated_at')->whereHas('roomTypes', fn ($query) => $query->whereKey($type->id))->findOrFail($details['rate_plan_id']);
            Booker::query()->findOrFail($details['booker_id']);
            $quote = app(StayPricing::class)->quote($plan, $type, $details['arrival'], $details['departure'], (int) $details['adults'], (int) $details['children']);
            if ($quote->unquotable !== null) {
                throw ValidationException::withMessages(['rate_plan_id' => $quote->unquotable]);
            }
            $warnings = app(StayRestrictions::class)->warnings($plan, $type, $details['arrival'], $details['departure']);
            $details['restriction_override_reason'] = app(StayRestrictions::class)->overrideReason($warnings, $details['restriction_override_reason'] ?? null);
            $rooms = (int) ($details['rooms'] ?? 1);
            if ($rooms < 1 || $rooms > 20) {
                throw ValidationException::withMessages(['rooms' => __('Choose between 1 and 20 rooms.')]);
            }
            unset($details['rooms']);
            app(TakeInventory::class)->handle($type, array_column($quote->nights, 'date'), $rooms);
            $groupId = $rooms > 1 ? (string) Str::ulid() : null;
            // The property lock serializes initial sequence creation as well as allocation.
            $property = Property::query()->whereKey(Tenancy::property()?->id)->lockForUpdate()->firstOrFail();
            $sequence = ReservationNumberSequence::query()->where('property_id', $property->id)->lockForUpdate()->first();
            $sequence ??= ReservationNumberSequence::query()->create(['last_number' => 0]);
            $room = 0;
            do {
                $sequence->increment('last_number');
                $number = $property->id.'-'.str_pad((string) $sequence->last_number, 6, '0', STR_PAD_LEFT);
                $reservation = Reservation::query()->create([
                    ...array_diff_key($details, ['room_id' => true]),
                    'booking_group_id' => $groupId,
                    'reservation_number' => $number,
                    'requires_reconciliation' => ($details['source'] ?? '') === 'outside-integration',
                    'payment_collector' => $details['payment_collector'] ?? 'property',
                    'status' => ReservationStatus::Confirmed,
                ]);
                Folio::query()->create(['reservation_id' => $reservation->id, 'currency' => $plan->currency]);
                foreach ($quote->nights as $night) {
                    $reservation->priceLines()->create(['date' => $night['date'], 'amount' => $night['total'], 'currency' => $quote->currency->value, 'rate_plan_id' => $plan->id]);
                }
                if ($room === 0 && ! empty($details['room_id'])) {
                    $reservation = app(AssignRoom::class)->handle($actor, $reservation, $details['room_id'], recordEvent: false);
                }
                if (! $reservation->imported || ! JurisdictionRules::defersImportedReservationCreated($property)) {
                    app(RecordLifecycleEvent::class)->handle($reservation, 'created', $actor);
                }
                Audit::record($actor, 'created', $reservation, 'Reservation created', ['reservation_number' => $number, 'restriction_override_reason' => $details['restriction_override_reason'], 'restriction_warnings' => $warnings]);
            } while (++$room < $rooms);

            return $reservation;
        }, attempts: 3));
    }
}
