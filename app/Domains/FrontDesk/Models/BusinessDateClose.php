<?php

namespace App\Domains\FrontDesk\Models;

use App\Support\Tenancy\BelongsToProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $property_id
 * @property string|null $actor_id
 * @property CarbonImmutable $business_date
 * @property CarbonImmutable|null $completed_at
 */
#[Fillable(['business_date', 'actor_id', 'completed_at'])]
class BusinessDateClose extends Model
{
    use BelongsToProperty, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['business_date' => 'immutable_date', 'completed_at' => 'immutable_datetime'];
    }
}
