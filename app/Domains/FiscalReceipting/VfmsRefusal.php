<?php

namespace App\Domains\FiscalReceipting;

use App\Domains\FiscalReceipting\Adapters\VfmsAdapter;
use App\Domains\Reservations\Models\Reservation;
use App\Support\Outbox\OutboxMessage;

final class VfmsRefusal
{
    /** @return array{message: string, refileUrl: string|null}|null */
    public static function of(Reservation $reservation, bool $canRecover): ?array
    {
        $message = OutboxMessage::query()->where('property_id', $reservation->property_id)
            ->where('integration', VfmsAdapter::INTEGRATION)->where('subject_type', $reservation->getMorphClass())
            ->where('subject_id', $reservation->id)->where('status', 'rejected')->orderBy('sequence')->first();
        if ($message === null) {
            return null;
        }
        $attempt = $message->attempts()->where('outcome', 'rejected')->orderByDesc('attempt_number')->first();
        $response = $attempt?->response_body === null ? null : json_decode($attempt->response_body, true, flags: JSON_THROW_ON_ERROR);
        $providerMessage = $response['body']['statusMessage'] ?? null;
        $summary = is_string($providerMessage) && preg_match('/^The Room ID \d{1,20} Is not Empty$/iD', $providerMessage)
            ? $providerMessage
            : __('ZRA refused this filing. Review the stay and its VFMS settings before re-filing.');

        return [
            'message' => $summary,
            'refileUrl' => $canRecover ? route('reservations.vfms.refile', [$reservation, $message]) : null,
        ];
    }
}
