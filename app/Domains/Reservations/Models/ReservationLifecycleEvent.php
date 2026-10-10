<?php

namespace App\Domains\Reservations\Models;

use App\Support\Tenancy\BelongsToProperty;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['reservation_id', 'type', 'sequence', 'occurred_at', 'business_date', 'actor_id', 'snapshot'])]
class ReservationLifecycleEvent extends Model
{
    use BelongsToProperty, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sequence' => 'integer', 'occurred_at' => 'immutable_datetime', 'business_date' => 'immutable_date', 'snapshot' => 'array'];
    }
}
