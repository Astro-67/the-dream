<?php

namespace App\Domains\Housekeeping\Models;

use App\Domains\Housekeeping\Policies\AssignmentPolicy;
use App\Domains\Inventory\Models\Room;
use App\Models\User;
use App\Support\Tenancy\BelongsToProperty;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $property_id
 * @property string $room_id
 * @property string $housekeeper_id
 * @property string $business_date
 * @property Carbon|null $completed_at
 * @property-read Room $room
 * @property-read User $housekeeper
 */
#[Fillable(['room_id', 'housekeeper_id', 'business_date', 'completed_at'])]
#[UsePolicy(AssignmentPolicy::class)]
class Assignment extends Model
{
    use BelongsToProperty, HasUlids;

    protected $table = 'housekeeping_assignments';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<User, $this> */
    public function housekeeper(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
