<?php

namespace App\Domains\FiscalReceipting\Actions;

use App\Domains\FiscalReceipting\Adapters\VfmsAdapter;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Outbox\OutboxMessage;
use App\Support\Outbox\OutboxRecovery;
use App\Support\Outbox\RestoreGate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CloseWithoutFiling
{
    public function handle(User $actor, Reservation $reservation, string $reason): void
    {
        Audit::validationRefusal($actor, $reservation, 'Close without filing refused', function () use ($actor, $reservation, $reason): void {
            $authorization = Gate::forUser($actor)->inspect('recoverVfms', $reservation);
            if ($authorization->denied()) {
                Audit::record($actor, 'refused', $reservation, 'Close without filing refused', ['result' => 'refused', 'stage' => 'authorization']);
            }
            $authorization->authorize();
            try {
                DB::transaction(function () use ($actor, $reservation, $reason): void {
                    $property = Property::query()->whereKey($reservation->property_id)->lockForUpdate()->firstOrFail();
                    app(RestoreGate::class)->assertOpen($property->id);
                    $stay = Reservation::query()->where('property_id', $property->id)->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
                    if (trim($reason) === '' || mb_strlen($reason) > 500) {
                        throw new RuntimeException('A reason of at most 500 characters is required.');
                    }
                    if ($property->archived_at !== null || $stay->status !== ReservationStatus::CheckedOut) {
                        throw new RuntimeException('Only departed stays at an active property can be closed without filing.');
                    }
                    if ($stay->getAttribute('fiscal_exception_reason') !== null) {
                        throw new RuntimeException('This stay was already closed without filing.');
                    }
                    $messages = OutboxMessage::query()->where('property_id', $property->id)->where('integration', VfmsAdapter::INTEGRATION)
                        ->where('subject_type', $stay->getMorphClass())->where('subject_id', $stay->id)->orderBy('sequence')->lockForUpdate()->get();
                    // Sandbox acceptance is not a fiscal filing. Missing acceptance mode is not proof of Sandbox.
                    if ($messages->contains(fn (OutboxMessage $message) => ! in_array($message->status, ['rejected', 'blocked'], true)
                        && ! ($message->status === 'delivered' && ($message->getAttribute('provider_references')['mode'] ?? null) === 'sandbox'))) {
                        throw new RuntimeException('Live, uncertain or active filings require reconciliation, not close without filing.');
                    }
                    if ($messages->contains(fn (OutboxMessage $message) => $message->claim_token !== null || ($message->claim_expires_at !== null && CarbonImmutable::parse($message->claim_expires_at)->isFuture()) || $message->attempts()->where('outcome', 'sending')->exists())) {
                        throw new RuntimeException('An active delivery attempt prevents recovery.');
                    }
                    $unfiled = $messages->whereIn('status', ['rejected', 'blocked']);
                    if ($unfiled->isEmpty()) {
                        throw new RuntimeException('There are no rejected or blocked VFMS filings to close.');
                    }
                    foreach ($unfiled as $message) {
                        app(OutboxRecovery::class)->supersede($actor, $property->id, $message->id, $reason);
                    }
                    $stay->setAttribute('fiscal_exception_reason', trim($reason));
                    $stay->save();
                    Audit::record($actor, 'closed_without_filing', $stay, 'Departed stay closed without filing', ['reason' => trim($reason), 'message_ids' => $unfiled->pluck('id')->all()]);
                });
            } catch (RuntimeException $refused) {
                throw ValidationException::withMessages(['vfms' => __($refused->getMessage())]);
            }
        });
    }
}
