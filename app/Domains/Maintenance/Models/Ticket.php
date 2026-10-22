<?php

namespace App\Domains\Maintenance\Models;

use App\Domains\Inventory\Models\Room;
use App\Domains\Maintenance\Enums\TicketCategory;
use App\Domains\Maintenance\Enums\TicketPriority;
use App\Domains\Maintenance\Policies\TicketPolicy;
use App\Models\User;
use App\Support\Tenancy\BelongsToProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A maintenance ticket: a fault or planned job at a room or common area. It never
 * changes room status or availability; out-of-service periods stay the inventory authority.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $property_id
 * @property string|null $room_id
 * @property string|null $location
 * @property string $title
 * @property string $description
 * @property TicketCategory $category
 * @property TicketPriority $priority
 * @property string|null $assignee_id
 * @property string|null $reported_by
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 * @property-read Room|null $room
 * @property-read User|null $assignee
 * @property-read User|null $reporter
 */
#[Fillable(['room_id', 'location', 'title', 'description', 'category', 'priority', 'assignee_id', 'reported_by', 'completed_at'])]
#[UsePolicy(TicketPolicy::class)]
class Ticket extends Model
{
    use BelongsToProperty, HasUlids;

    protected $table = 'maintenance_tickets';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['category' => TicketCategory::class, 'priority' => TicketPriority::class, 'completed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /** @return HasMany<TicketEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(TicketEvent::class);
    }

    public function place(): ?string
    {
        return $this->room !== null ? __('Room :number', ['number' => $this->room->number]) : $this->location;
    }

    /** Appends a history entry; called by the actions inside their transaction. */
    public function recordEvent(?User $actor, string $event, ?array $before, ?array $after): void
    {
        $this->events()->create(['actor_id' => $actor?->id, 'event' => $event, 'before' => $before, 'after' => $after, 'recorded_at' => now()]);
    }
}
