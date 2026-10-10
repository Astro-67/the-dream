<?php

namespace App\Domains\Guests\Models;

use App\Support\Tenancy\BelongsToProperty;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['reservation_id', 'guest_id'])]
class ReservationGuest extends Model
{
    use BelongsToProperty, HasUlids;
}
