<?php

namespace App\Domains\Rates\Models;

use App\Support\Tenancy\BelongsToProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Stored nightly amounts, all in the rate plan's integer minor units.
 *
 * @property string $id
 * @property string $rate_plan_id
 * @property string $room_type_id
 * @property int $base_price
 * @property int $extra_adult
 * @property int $extra_child
 * @property CarbonImmutable $date
 */
#[Fillable(['rate_plan_id', 'room_type_id', 'date', 'base_price', 'extra_adult', 'extra_child'])]
class RatePrice extends Model
{
    use BelongsToProperty, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'base_price' => 'integer', 'extra_adult' => 'integer', 'extra_child' => 'integer'];
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
