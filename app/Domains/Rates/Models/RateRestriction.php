<?php

namespace App\Domains\Rates\Models;

use App\Support\Tenancy\BelongsToProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Selling limits for one rate plan, room type and night. Stored and shown only;
 * Spec 4 enforces them when booking.
 *
 * @property string $id
 * @property string $rate_plan_id
 * @property string $room_type_id
 * @property int $min_stay
 * @property bool $closed_to_arrival
 * @property bool $stop_sell
 * @property CarbonImmutable $date
 */
#[Fillable(['rate_plan_id', 'room_type_id', 'date', 'min_stay', 'closed_to_arrival', 'stop_sell'])]
class RateRestriction extends Model
{
    use BelongsToProperty, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'min_stay' => 'integer', 'closed_to_arrival' => 'boolean', 'stop_sell' => 'boolean'];
    }

    /**
     * Stored as a plain Y-m-d, so date strings match it on every database (SQLite would add a time).
     *
     * @return Attribute<CarbonImmutable, mixed>
     */
    protected function date(): Attribute
    {
        return Attribute::set(fn (mixed $value) => $this->asDate($value)->format('Y-m-d'));
    }
}
