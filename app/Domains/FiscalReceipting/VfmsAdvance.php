<?php

namespace App\Domains\FiscalReceipting;

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Billing\Models\PostingApproval;
use App\Domains\Billing\Models\Receipt;
use App\Domains\FiscalReceipting\Adapters\VfmsAdapter;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Enums\TourOperatorPaysFor;
use App\Domains\Reservations\Models\Reservation;
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
        return match (true) {
            $property->demo || $property->archived_at !== null || ! $property->isLive() || ! JurisdictionRules::usesVfms($property) => __('Advance fiscal receipts require an operational Zanzibar property.'),
            $reservation->status !== ReservationStatus::Confirmed || $payment->type !== 'payment' || $payment->category !== 'deposit' || $payment->amount >= 0 => __('Only a payment received before check-in can be filed as an advance.'),
            $payment->method === 'channel_collected' => __('Channel-collected payments are not supported by the documented B2B advance flow.'),
            $reservation->getAttribute('booked_through') !== 'tour-operator' => __('The documented advance endpoint supports tour-operator/B2B payments only.'),
            ! filled($reservation->getAttribute('tour_operator_name')) || ! filled($reservation->getAttribute('tour_operator_registration'))
                || ! $reservation->getAttribute('tour_operator_pays_for') instanceof TourOperatorPaysFor => __('Complete the tour operator name, registration and payment responsibilities before filing.'),
            $reservation->room_id === null => __('Assign a room before filing an advance receipt.'),
            $reservation->priceLines()->value('currency') !== $payment->currency->value => __('The advance payment must use the reservation currency.'),
            Posting::query()->where('folio_id', $payment->folio_id)->where('type', 'refund')->whereNull('related_posting_id')->exists()
                || Posting::query()->where('related_posting_id', $payment->id)->exists()
                || PostingApproval::query()->where('folio_id', $payment->folio_id)->where('status', 'pending')->exists() => __('Resolve payment corrections before filing an advance receipt.'),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public static function checkInDetails(Reservation $reservation, Property $property): array
    {
        $message = self::latest($reservation);
        if ($message === null) {
            return [];
        }
        $references = $message->getAttribute('provider_references') ?? [];
        if (! JurisdictionRules::usesVfms($property) || $message->status !== 'delivered' || empty($references['bookingNumber']) || empty($references['receiptNumber'])
            || ($references['mode'] ?? null) !== VfmsCredential::for($property)->mode->value) {
            throw ValidationException::withMessages(['vfms' => __('The advance receipt must be accepted in the current VFMS mode before check-in. Resolve its ZRA filing first.')]);
        }

        return ['advance_booking' => array_intersect_key($references, array_flip(['bookingNumber', 'receiptNumber', 'mode']))];
    }

    public static function ensureMutable(Reservation $reservation, string $field = 'reservation'): void
    {
        // shortcut: no documented advance reversal endpoint, add reconciled cancellation before allowing corrections.
        if (self::latest($reservation) !== null) {
            throw ValidationException::withMessages([$field => __('This reservation has a VFMS advance filing. Automatic reservation changes and corrections of the filed payment are not supported; reconcile the advance with ZRA outside Karafuu.')]);
        }
    }

    /** @return array<string, mixed>|null */
    public static function forReceipt(Receipt $receipt, bool $canFile): ?array
    {
        $payment = $receipt->posting()->firstOrFail();
        $reservation = Reservation::query()->findOrFail(Folio::query()->findOrFail($payment->folio_id)->reservation_id);
        $property = Property::query()->findOrFail($reservation->property_id);
        if (! JurisdictionRules::usesVfms($property) || $payment->category !== 'deposit') {
            return null;
        }
        $message = self::latest($reservation);
        if ($message !== null && ($message->payload['posting_id'] ?? null) !== $payment->id) {
            return ['status' => 'unavailable', 'reason' => __('An advance receipt already belongs to another payment on this reservation.')];
        }
        $references = $message?->getAttribute('provider_references') ?? [];
        $reason = $message === null ? self::ineligible($payment, $reservation, $property) : ($message->status === 'rejected' ? VfmsRefusal::summary($message) : null);
        $receiptUrl = $message?->status === 'delivered' ? ($references['receiptUrl'] ?? null) : null;

        return [
            'status' => $message->status ?? 'not_filed',
            'reason' => $reason,
            'company' => $reservation->getAttribute('tour_operator_name'),
            'fileUrl' => $message === null && $canFile && $reason === null ? route('receipts.vfms-advance.store', $receipt) : null,
            'reservationUrl' => route('reservations.show', $reservation),
            'bookingNumber' => $references['bookingNumber'] ?? null,
            'receiptNumber' => $references['receiptNumber'] ?? null,
            'receiptUrl' => $receiptUrl,
            'qrSvg' => $receiptUrl === null ? null : CheckoutReport::qr($receiptUrl),
            'mode' => $references['mode'] ?? $message->payload['mode'] ?? VfmsCredential::for($property)->mode->value,
        ];
    }
}
