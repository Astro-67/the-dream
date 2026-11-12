<?php

namespace App\Domains\FiscalReceipting;

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Billing\Models\PostingApproval;
use App\Domains\Billing\Models\Receipt;
use App\Domains\FiscalReceipting\Adapters\VfmsAdapter;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\ReservationLifecycleEvent;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Validation\ValidationException;

final class VfmsAdvance
{
    public static function latest(Reservation $reservation, bool $lock = false): ?OutboxMessage
    {
        return OutboxMessage::query()->where('integration', VfmsAdapter::INTEGRATION)
            ->where('property_id', $reservation->property_id)
            ->where('subject_type', $reservation->getMorphClass())->where('subject_id', $reservation->id)
            ->where('event', 'advanceBooking')->orderByDesc('sequence')
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
    }

    public static function ineligible(Posting $payment, Reservation $reservation, Property $property): ?string
    {
        $reason = self::paymentIneligible($payment, $reservation, $property);
        if ($reason !== null) {
            return $reason;
        }

        return $reservation->status !== ReservationStatus::Confirmed ? __('Only a payment received before check-in can be filed as an advance.') : null;
    }

    /**
     * The payer ZRA names on the advance receipt, prefilled for staff: the tour operator, else the lead guest.
     *
     * @return array{name: string|null, regNumber: string|null}
     */
    private static function payer(Reservation $reservation): array
    {
        $operator = $reservation->getAttribute('booked_through') === 'tour-operator' && filled($reservation->getAttribute('tour_operator_name'));

        return $operator
            ? ['name' => $reservation->getAttribute('tour_operator_name'), 'regNumber' => $reservation->getAttribute('tour_operator_registration')]
            : ['name' => Reservation::guestNames(collect([$reservation]))->get($reservation->id), 'regNumber' => null];
    }

    private static function paymentIneligible(Posting $payment, Reservation $reservation, Property $property): ?string
    {
        return match (true) {
            $property->demo || $property->archived_at !== null || ! $property->isLive() || ! JurisdictionRules::usesVfms($property) => __('Advance fiscal receipts require an operational Zanzibar property.'),
            $payment->type !== 'payment' || $payment->category !== 'deposit' || $payment->amount >= 0 => __('Choose a payment received before check-in.'),
            $reservation->room_id === null => __('Assign a room before filing an advance receipt.'),
            $reservation->priceLines()->value('currency') !== $payment->currency->value => __('The advance payment must use the reservation currency.'),
            Posting::query()->where('folio_id', $payment->folio_id)->where('type', 'refund')->whereNull('related_posting_id')->exists()
                || Posting::query()->where('related_posting_id', $payment->id)->exists()
                || PostingApproval::query()->where('folio_id', $payment->folio_id)->where('status', 'pending')->exists() => __('Resolve payment corrections before filing an advance receipt.'),
            default => null,
        };
    }

    public static function linked(Reservation $reservation, bool $lock = false): ?ReservationLifecycleEvent
    {
        return $reservation->lifecycleEvents()->where('type', 'advance_booking_linked')->reorder('sequence', 'desc')
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
    }

    public static function checkInFiling(Reservation $reservation, bool $lock = false): ?OutboxMessage
    {
        return OutboxMessage::query()->where('integration', VfmsAdapter::INTEGRATION)
            ->where('property_id', $reservation->property_id)
            ->where('subject_type', $reservation->getMorphClass())->where('subject_id', $reservation->id)
            ->where('event', 'advanceAccommodation')->orderByDesc('sequence')
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
    }

    public static function paymentId(Reservation $reservation): ?string
    {
        return self::latest($reservation)?->payload['posting_id'] ?? self::linked($reservation)?->snapshot['posting_id'] ?? null;
    }

    public static function linkIneligible(Posting $payment, Reservation $reservation, Property $property, bool $lock = false): ?string
    {
        $reason = self::paymentIneligible($payment, $reservation, $property);
        if ($reason !== null) {
            return $reason;
        }
        if (self::latest($reservation, $lock) !== null) {
            return __('A PMS advance filing already exists. Resolve that filing instead of replacing its references.');
        }
        $filing = self::checkInFiling($reservation, $lock);
        if ($filing !== null && ($filing->status !== 'rejected' || ($filing->payload['mode'] ?? null) !== VfmsCredential::for($property)->mode->value)) {
            return __('Advance references cannot change after an accepted or uncertain check-in, or across VFMS modes.');
        }
        if ($reservation->status !== ReservationStatus::Confirmed
            && ! ($reservation->status === ReservationStatus::CheckedIn && $filing?->status === 'rejected' && self::linked($reservation) !== null)) {
            return __('Link an advance before check-in, or correct references after a confirmed rejection of its advance check-in.');
        }

        return null;
    }

    /** @return array<string, mixed> */
    public static function checkInDetails(Reservation $reservation, Property $property): array
    {
        $message = self::latest($reservation);
        $linked = $message === null ? self::linked($reservation) : null;
        if ($message === null && $linked === null) {
            return [];
        }
        $references = $message?->getAttribute('provider_references') ?? $linked?->snapshot['advance_booking'] ?? [];
        if (! JurisdictionRules::usesVfms($property) || ($message !== null && $message->status !== 'delivered')
            || ! is_string($references['bookingNumber'] ?? null) || ! filled($references['bookingNumber'])
            || ! is_string($references['receiptNumber'] ?? null) || ! filled($references['receiptNumber'])
            || ($references['mode'] ?? null) !== VfmsCredential::for($property)->mode->value) {
            throw ValidationException::withMessages(['vfms' => __('The advance receipt must be accepted in the current VFMS mode before check-in. Resolve its ZRA filing first.')]);
        }

        return ['advance_booking' => array_intersect_key($references, array_flip(['bookingNumber', 'receiptNumber', 'mode', 'source']))];
    }

    public static function ensureMutable(Reservation $reservation, string $field = 'reservation'): void
    {
        // shortcut: no documented advance reversal endpoint, add reconciled cancellation before allowing corrections.
        if (self::latest($reservation) !== null || self::linked($reservation) !== null) {
            throw ValidationException::withMessages([$field => __('This reservation has a VFMS advance filing. Automatic reservation changes and corrections of the filed payment are not supported; reconcile the advance with ZRA outside Karafuu.')]);
        }
    }

    /** @return array<string, mixed>|null */
    public static function forReceipt(Receipt $receipt, bool $canFile): ?array
    {
        $payment = $receipt->posting()->firstOrFail();
        if ($payment->category !== 'deposit') {
            return null;
        }
        $reservation = Reservation::query()->findOrFail(Folio::query()->findOrFail($payment->folio_id)->reservation_id);
        $property = Property::query()->findOrFail($reservation->property_id);
        if (! JurisdictionRules::usesVfms($property) || $payment->category !== 'deposit') {
            return null;
        }
        $message = self::latest($reservation);
        $linked = $message === null ? self::linked($reservation) : null;
        $paymentId = $message->payload['posting_id'] ?? $linked?->snapshot['posting_id'] ?? null;
        if ($paymentId !== null && $paymentId !== $payment->id) {
            return ['status' => 'unavailable', 'reason' => __('An advance receipt already belongs to another payment on this reservation.')];
        }
        $references = $message?->getAttribute('provider_references') ?? $linked?->snapshot['advance_booking'] ?? [];
        $filing = $linked === null ? null : self::checkInFiling($reservation);
        $reason = $linked !== null
            ? match ($filing?->status) {
                'rejected' => VfmsRefusal::summary($filing),
                'unknown', 'exhausted_unknown' => __('The advance check-in outcome is unknown. Do not replace its references or send an ordinary check-in; reconcile the existing ZRA filing.'),
                'delivered' => __('ZRA accepted check-in using these externally issued references. The advance receipt was not issued by Karafuu.'),
                default => __('These externally issued references are validated by ZRA at check-in. Karafuu has not issued or independently verified this advance receipt.'),
            }
        : ($message === null ? self::ineligible($payment, $reservation, $property) : ($message->status === 'rejected' ? VfmsRefusal::summary($message) : null));
        $receiptUrl = $linked !== null || $message?->status === 'delivered' ? ($references['receiptUrl'] ?? null) : null;

        return [
            'status' => $message->status ?? ($linked === null ? 'not_filed' : 'linked'),
            'source' => $linked === null ? 'pms' : 'external',
            'checkInStatus' => $filing->status ?? null,
            'reason' => $reason,
            'payer' => self::payer($reservation),
            'fileUrl' => $message === null && $linked === null && $canFile && $reason === null ? route('receipts.vfms-advance.store', $receipt) : null,
            'linkUrl' => $canFile && self::linkIneligible($payment, $reservation, $property) === null ? route('receipts.vfms-advance.store', $receipt) : null,
            'reservationUrl' => route('reservations.show', $reservation),
            'bookingNumber' => $references['bookingNumber'] ?? null,
            'receiptNumber' => $references['receiptNumber'] ?? null,
            'receiptUrl' => $receiptUrl,
            'qrSvg' => $receiptUrl === null ? null : CheckoutReport::qr($receiptUrl),
            'mode' => $references['mode'] ?? $message->payload['mode'] ?? VfmsCredential::for($property)->mode->value,
        ];
    }
}
