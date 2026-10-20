<?php

namespace App\Domains\Reservations\Actions;

use App\Domains\Billing\Models\Folio;
use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Guests\Actions\AttachReservationGuest;
use App\Domains\Guests\Models\Booker;
use App\Domains\Inventory\Actions\TakeInventory;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Rates\StayPricing;
use App\Domains\Rates\StayRestrictions;
use App\Domains\Reservations\BookingRules;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\ReservationNumberSequence;
use App\Domains\Subscriptions\Entitlements;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
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
            $property = Tenancy::property() ?? throw MissingTenantContext::forProperty(Reservation::class);
            app(Entitlements::class)->lockForNewSales($property->demo);
            $type = RoomType::query()->findOrFail($details['room_type_id']);
            $plan = RatePlan::query()->whereNull('deactivated_at')->whereHas('roomTypes', fn ($query) => $query->whereKey($type->id))->findOrFail($details['rate_plan_id']);
            if (! empty($details['booker_id'])) {
                Booker::query()->findOrFail($details['booker_id']);
            }
            $guest = $details['lead_guest'] ?? null;
            unset($details['lead_guest']);
            $settings = $property->settings()['front_desk_settings'];
            if (($details['source'] ?? '') === 'walk-in') {
                if (! $settings['walk_ins_enabled']) {
                    throw ValidationException::withMessages(['source' => __('Walk-ins are disabled for this property.')]);
                }
                if ($details['arrival'] !== BusinessDate::of($property)) {
                    throw ValidationException::withMessages(['arrival' => __('Walk-ins must arrive on the current business date.')]);
                }
                if ($settings['walk_in_requires_id'] && (empty($guest['document_type']) || empty($guest['document_number']))) {
                    throw ValidationException::withMessages(['lead_guest.document_type' => __('Walk-ins require an ID document.')]);
                }
            }
            if ($guest !== null && ! empty($guest['document_type']) && JurisdictionRules::requiresPassport($property, $guest['nationality'] ?? null) && $guest['document_type'] !== 'passport') {
                throw ValidationException::withMessages(['lead_guest.document_type' => __('Foreign guests require a passport.')]);
            }
            $nightlyRate = $details['nightly_rate'] ?? null;
            unset($details['nightly_rate']);
            $quote = app(StayPricing::class)->quote($plan, $type, $details['arrival'], $details['departure'], (int) $details['adults'], (int) $details['children']);
            if ($quote->unquotable !== null) {
                throw ValidationException::withMessages(['rate_plan_id' => $quote->unquotable]);
            }
            $bookingWarnings = app(BookingRules::class)->warnings($property, $details['arrival'], $details['departure']);
            app(BookingRules::class)->authorizeOverride($actor, $bookingWarnings);
            $warnings = [...$bookingWarnings, ...app(StayRestrictions::class)->warnings($plan, $type, $details['arrival'], $details['departure'])];
            $details['restriction_override_reason'] = app(StayRestrictions::class)->overrideReason($warnings, $details['restriction_override_reason'] ?? null);
            $rooms = (int) ($details['rooms'] ?? 1);
            if ($rooms < 1 || $rooms > 20) {
                throw ValidationException::withMessages(['rooms' => __('Choose between 1 and 20 rooms.')]);
            }
            unset($details['rooms']);
            app(TakeInventory::class)->handle($type, array_column($quote->nights, 'date'), $rooms);
            $groupId = $rooms > 1 ? (string) Str::ulid() : null;
            // The property lock serializes initial sequence creation as well as allocation.
            $property = Property::query()->whereKey($property->id)->lockForUpdate()->firstOrFail();
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
                    $reservation->priceLines()->create(['date' => $night['date'], 'amount' => $nightlyRate ?? $night['total'], 'currency' => $quote->currency->value, 'rate_plan_id' => $plan->id]);
                }
                if ($guest !== null && $actor !== null) {
                    app(AttachReservationGuest::class)->handle($actor, $reservation, [
                        'name' => trim($guest['first_name'].' '.($guest['last_name'] ?? '')),
                        'email' => $guest['email'] ?? null, 'phone' => $guest['phone'] ?? null, 'nationality' => $guest['nationality'] ?? null,
                        'documents' => empty($guest['document_type']) ? [] : [['type' => $guest['document_type'], 'number' => $guest['document_number'], 'nationality' => $guest['nationality'] ?? null]],
                    ]);
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
