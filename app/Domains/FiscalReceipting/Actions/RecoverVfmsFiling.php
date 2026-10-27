<?php

namespace App\Domains\FiscalReceipting\Actions;

use App\Domains\FiscalReceipting\Adapters\VfmsAdapter;
use App\Domains\Reservations\Models\Reservation;
use App\Models\User;
use App\Support\Outbox\OutboxMessage;
use App\Support\Outbox\OutboxRecovery;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class RecoverVfmsFiling
{
    public function handle(User $actor, Reservation $reservation, OutboxMessage $message, string $reason, bool $refile): void
    {
        $message = OutboxMessage::query()->where('property_id', $reservation->property_id)
            ->where('integration', VfmsAdapter::INTEGRATION)->where('subject_type', $reservation->getMorphClass())
            ->where('subject_id', $reservation->id)->findOrFail($message->id);
        try {
            $recovery = app(OutboxRecovery::class);
            if ($refile) {
                $recovery->correct($actor, $reservation->property_id, $message->id, $reason, []);
            } else {
                $recovery->retry($actor, $reservation->property_id, $message->id, $reason);
            }
        } catch (RuntimeException $refused) {
            throw ValidationException::withMessages(['vfms' => __($refused->getMessage())]);
        }
    }
}
