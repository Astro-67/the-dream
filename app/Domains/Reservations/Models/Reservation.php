<?php

namespace App\Domains\Reservations\Models;

use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Policies\ReservationPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use App\Support\Tenancy\BelongsToProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property string $payment_collector
 */
#[Fillable(['reservation_number', 'room_type_id', 'rate_plan_id', 'booker_id', 'arrival', 'departure', 'adults', 'children', 'source', 'payment_collector', 'status'])]
#[UsePolicy(ReservationPolicy::class)]
class Reservation extends Model
{
    use BelongsToProperty, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['arrival' => 'immutable_date', 'departure' => 'immutable_date', 'adults' => 'integer', 'children' => 'integer', 'status' => ReservationStatus::class];
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

    /** @return HasMany<ReservationLifecycleEvent, $this> */
    public function lifecycleEvents(): HasMany
    {
        return $this->hasMany(ReservationLifecycleEvent::class)->orderBy('sequence');
    }
}
