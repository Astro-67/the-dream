<?php

namespace App\Domains\Reservations\Models;

use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Policies\ReservationPolicy;
use App\Support\Tenancy\BelongsToProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $property_id
 * @property string $reservation_number
 * @property CarbonImmutable $arrival
 * @property CarbonImmutable $departure
 * @property ReservationStatus $status
 * @property string|null $room_id
 * @property string $payment_collector
 * @property int|null $channel_collected_amount minor units, when the channel collects
 */
#[Fillable(['booking_group_id', 'reservation_number', 'room_type_id', 'rate_plan_id', 'booker_id', 'arrival', 'departure', 'adults', 'children', 'source', 'source_reference', 'imported', 'payment_collector', 'status', 'room_id', 'restriction_override_reason', 'channel_reference', 'outside_integration_reason', 'requires_reconciliation', 'channel_collected_amount'])]
#[UsePolicy(ReservationPolicy::class)]
class Reservation extends Model
{
    use BelongsToProperty, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['imported' => 'boolean', 'requires_reconciliation' => 'boolean', 'arrival' => 'immutable_date', 'departure' => 'immutable_date', 'adults' => 'integer', 'children' => 'integer', 'channel_collected_amount' => 'integer', 'status' => ReservationStatus::class];
    }

    public function isOverstay(string $businessDate): bool
    {
        return $this->status === ReservationStatus::CheckedIn && $this->departure->toDateString() < $businessDate;
    }

    public function getRouteKeyName(): string
    {
        return 'reservation_number';
    }

    /** @return HasMany<ReservationPriceLine, $this> */
    public function priceLines(): HasMany
    {
        return $this->hasMany(ReservationPriceLine::class)->orderBy('date');
    }

    /**
     * The price line for a night of the stay. Price lines end before departure; a night at
     * or after departure (a departure not yet checked out) repeats the last night's price.
     */
    public function nightPrice(string $date): ?ReservationPriceLine
    {
        return $this->priceLines()->whereDate('date', $date)->first()
            ?? ($date >= $this->departure->toDateString()
                ? $this->priceLines()->whereDate('date', '<', $this->departure->toDateString())->reorder()->latest('date')->first()
                : null);
    }

    /** @return HasMany<ReservationLifecycleEvent, $this> */
    public function lifecycleEvents(): HasMany
    {
        return $this->hasMany(ReservationLifecycleEvent::class)->orderBy('sequence');
    }
}
