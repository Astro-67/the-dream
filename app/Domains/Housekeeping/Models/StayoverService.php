<?php

namespace App\Domains\Housekeeping\Models;

use App\Support\Tenancy\BelongsToProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $room_id
 * @property string|null $actor_id
 * @property CarbonImmutable $business_date
 */
#[Fillable(['room_id', 'actor_id', 'business_date'])]
class StayoverService extends Model
{
    use BelongsToProperty, HasUlids;

    protected $table = 'housekeeping_stayover_services';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['business_date' => 'immutable_date'];
    }
}
