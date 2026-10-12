<?php

namespace App\Domains\Housekeeping\Models;

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Models\User;
use App\Support\Tenancy\BelongsToProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $property_id
 * @property string $room_id
 * @property string|null $actor_id
 * @property HousekeepingStatus $from_status
 * @property HousekeepingStatus $to_status
 * @property string $reason
 * @property CarbonImmutable $changed_at
 * @property-read User|null $actor
 */
#[Fillable(['room_id', 'actor_id', 'from_status', 'to_status', 'reason', 'changed_at'])]
class StatusChange extends Model
{
    use BelongsToProperty, HasUlids;

    protected $table = 'housekeeping_status_changes';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['from_status' => HousekeepingStatus::class, 'to_status' => HousekeepingStatus::class, 'changed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Housekeeping history is append-only.'));
        static::deleting(fn () => throw new LogicException('Housekeeping history is append-only.'));
    }
}
