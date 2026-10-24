<?php

namespace App\Domains\Guests\Models;

use App\Support\Tenancy\BelongsToProperty;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** `departed_at` is set when this guest leaves before the stay checks out (Guest departure). */
#[Fillable(['reservation_id', 'guest_id', 'departed_at'])]
class ReservationGuest extends Model
{
    use BelongsToProperty, HasUlids;

    protected function casts(): array
    {
        return ['departed_at' => 'immutable_datetime'];
    }
}
