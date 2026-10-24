<?php

namespace App\Domains\Reservations\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable $business_date
 * @property array<string, mixed> $snapshot
 */
#[Fillable(['reservation_id', 'type', 'sequence', 'occurred_at', 'business_date', 'actor_id', 'snapshot', 'test'])]
class ReservationLifecycleEvent extends Model
{
    use BelongsToProperty, HasUlids;

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sequence' => 'integer', 'occurred_at' => 'immutable_datetime', 'business_date' => 'immutable_date', 'snapshot' => 'array', 'test' => 'boolean'];
    }
}
