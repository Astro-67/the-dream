<?php

namespace App\Domains\Reservations\Models;

use App\Support\Tenancy\BelongsToProperty;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['reservation_id', 'rate_plan_id', 'date', 'amount', 'currency'])]
class ReservationPriceLine extends Model
{
    use BelongsToProperty, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'amount' => 'integer'];
    }
}
