<?php

namespace App\Domains\Maintenance\Models;

use App\Domains\Inventory\Models\Room;
use App\Domains\Maintenance\Enums\TicketCategory;
use App\Domains\Maintenance\Enums\TicketPriority;
use App\Domains\Maintenance\Policies\TicketPolicy;
use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Models\User;
use App\Support\Tenancy\Access;
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

    /** Who may be assigned this ticket: a member who may report maintenance at its property. */
    public function acceptsAssignee(User $user): bool
    {
        return Access::allows($user, OrganizationPermission::MaintenanceReport, $this);
    }

    /**
     * The ticket's history for display, naming removed members as former members.
     *
     * @return list<array{id: string, event: string, actor: string, from: string|null, to: string|null, recordedAt: string}>
     */
    public function history(): array
    {
        $events = $this->events()->with('actor')->orderBy('recorded_at')->orderBy('id')->get();
        $names = User::query()->whereIn('id', $events->flatMap(fn (TicketEvent $event) => [$event->before['assignee_id'] ?? null, $event->after['assignee_id'] ?? null])->filter())->pluck('name', 'id');
        $name = fn (?string $id) => $id === null ? null : $names[$id] ?? __('Former member');

        return $events->map(fn (TicketEvent $event) => [
            'id' => $event->id, 'event' => $event->event,
            'actor' => $event->actor->name ?? __('Former member'),
            'from' => $name($event->before['assignee_id'] ?? null),
            'to' => $name($event->after['assignee_id'] ?? null),
            'recordedAt' => $event->recorded_at->toIso8601String(),
        ])->values()->all();
    }

    /**
     * Appends a history entry; called by the actions inside their transaction.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function recordEvent(?User $actor, string $event, ?array $before, ?array $after): void
    {
        $this->events()->create(['actor_id' => $actor?->id, 'event' => $event, 'before' => $before, 'after' => $after, 'recorded_at' => now()]);
    }
}
