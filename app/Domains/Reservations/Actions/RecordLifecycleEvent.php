<?php

namespace App\Domains\Reservations\Actions;

use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\ReservationLifecycleEvent;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

class RecordLifecycleEvent
{
    /** @param array<string, mixed> $details */
    public function handle(Reservation $reservation, string $type, ?User $actor, array $details = []): ReservationLifecycleEvent
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Lifecycle events require the reservation transaction.');
        }
        $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
        $property = Property::query()->findOrFail($locked->property_id);

        return $locked->lifecycleEvents()->create([
            'type' => $type,
            'sequence' => ((int) $locked->lifecycleEvents()->max('sequence')) + 1,
            'occurred_at' => now(),
            // ponytail: before Spec 7 owns the open business date, use the property's local date.
            'business_date' => now($property->timezone)->toDateString(),
            'actor_id' => $actor?->id,
            'snapshot' => [...$details, ...$locked->only(['reservation_number', 'room_id', 'room_type_id', 'rate_plan_id', 'booker_id', 'arrival', 'departure', 'adults', 'children', 'source', 'channel_reference', 'outside_integration_reason', 'requires_reconciliation', 'payment_collector', 'status']), 'price_lines' => $locked->priceLines->map->only(['date', 'amount', 'currency', 'rate_plan_id'])->all()],
        ]);
    }
}
