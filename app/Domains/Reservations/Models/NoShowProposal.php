<?php

namespace App\Domains\Reservations\Models;

use App\Domains\Reservations\Policies\NoShowProposalPolicy;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\BelongsToProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * Night audit's proposal that a reservation not arrived by the end of its
 * arrival business date is a no-show. A manager confirms or rejects it;
 * nothing changes the reservation until confirmation. One per reservation.
 *
 * @property string $id
 * @property string $property_id
 * @property string $reservation_id
 * @property CarbonImmutable $business_date the close that proposed it
 * @property string $status 'proposed', 'confirmed' or 'rejected'
 * @property string|null $resolved_by
 * @property CarbonImmutable|null $resolved_at
 * @property-read Reservation $reservation
 */
#[Fillable(['reservation_id', 'business_date', 'status', 'resolved_by', 'resolved_at'])]
#[UsePolicy(NoShowProposalPolicy::class)]
class NoShowProposal extends Model
{
    use BelongsToProperty, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['business_date' => 'immutable_date', 'resolved_at' => 'immutable_datetime'];
    }

    /** Locks a proposal still awaiting a decision; refuses one already resolved. Needs a transaction. */
    public static function lockOpen(self $proposal): self
    {
        $locked = self::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== 'proposed') {
            throw ValidationException::withMessages(['no_show' => __('This no-show has already been confirmed or rejected.')]);
        }

        return $locked;
    }

    /** @param 'confirmed'|'rejected' $status */
    public function resolve(User $actor, string $status): self
    {
        $this->update(['status' => $status, 'resolved_by' => $actor->id, 'resolved_at' => now()]);
        Audit::record($actor, $status, $this, 'No-show '.$status, ['reservation_id' => $this->reservation_id]);

        return $this;
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
