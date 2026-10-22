<?php

namespace App\Domains\Maintenance\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One entry of a ticket's history: reported, assigned, reassigned or completed,
 * with the actor and the before/after details. Append-only.
 *
 * @property string $id
 * @property string $ticket_id
 * @property string|null $actor_id
 * @property string $event
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property CarbonImmutable $recorded_at
 * @property-read User|null $actor
 */
#[Fillable(['ticket_id', 'actor_id', 'event', 'before', 'after', 'recorded_at'])]
class TicketEvent extends Model
{
    use BelongsToProperty, HasUlids;

    protected $table = 'maintenance_ticket_events';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'recorded_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Maintenance history is append-only.'));
        static::deleting(fn () => throw new LogicException('Maintenance history is append-only.'));
    }
}
