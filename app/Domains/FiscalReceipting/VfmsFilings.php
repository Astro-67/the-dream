<?php

namespace App\Domains\FiscalReceipting;

use App\Domains\FiscalReceipting\Adapters\VfmsAdapter;
use App\Domains\Reservations\Models\Reservation;
use App\Support\Outbox\OutboxMessage;

/** Every VFMS call of one stay, in filing order, for the reservation page. */
final class VfmsFilings
{
    private const CALLS = [
        'accommodation' => 'Check-in', 'changeRoom' => 'Room move', 'changeRoomPrice' => 'Room price changed',
        'addGuest' => 'Guest added', 'changeProfile' => 'Profile corrected', 'services' => 'Extras',
        'guestDeparture' => 'Guest left', 'checkout' => 'Check-out',
    ];

    /** @return list<array{id: string, call: string, status: string, attempts: int, number: string|null, message: string|null, refileUrl: string|null, retryUrl: string|null}> */
    public static function of(Reservation $reservation, bool $canRecover): array
    {
        return OutboxMessage::query()->where('property_id', $reservation->property_id)
            ->where('integration', VfmsAdapter::INTEGRATION)->where('subject_type', $reservation->getMorphClass())
            ->where('subject_id', $reservation->id)->orderBy('sequence')->get()
            ->map(function (OutboxMessage $message) use ($reservation, $canRecover) {
                $references = $message->getAttribute('provider_references') ?? [];
                $number = $references['reportNo'] ?? $references['receiptNumber'] ?? $references['responseNumber'] ?? null;

                return [
                    'id' => $message->id,
                    'call' => self::CALLS[$message->event] ?? $message->event,
                    'status' => match ($message->status) {
                        'delivered' => 'filed',
                        'rejected' => 'refused',
                        'unknown', 'exhausted_unknown' => 'unknown',
                        default => 'waiting',
                    },
                    'attempts' => (int) $message->attempt_count,
                    'number' => $number === null ? null : (string) $number,
                    'message' => $message->status === 'rejected' ? VfmsRefusal::summary($message) : null,
                    // Unknown outcomes never get an action: VFMS cannot prove whether ZRA kept them (unknownRetrySafe is false).
                    'refileUrl' => $canRecover && $message->status === 'rejected' ? route('reservations.vfms.refile', [$reservation, $message]) : null,
                    'retryUrl' => $canRecover && in_array($message->status, ['retryable', 'exhausted'], true) ? route('reservations.vfms.retry', [$reservation, $message]) : null,
                ];
            })->all();
    }
}
