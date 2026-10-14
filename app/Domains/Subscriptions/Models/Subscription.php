<?php

namespace App\Domains\Subscriptions\Models;

use App\Domains\Subscriptions\Enums\SubscriptionStatus;
use App\Domains\Subscriptions\Policies\SubscriptionPolicy;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An organization's one subscription to Thedream. Its status changes only through
 * TransitionSubscription, which records each change with a reason.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $plan_id
 * @property SubscriptionStatus $status
 * @property CarbonImmutable|null $trial_ends_at
 * @property CarbonImmutable|null $current_period_ends_at
 * @property bool $cancel_at_period_end
 * @property Plan $plan
 */
#[Fillable(['plan_id', 'status', 'trial_ends_at', 'current_period_ends_at', 'cancel_at_period_end'])]
#[UsePolicy(SubscriptionPolicy::class)]
class Subscription extends Model
{
    use BelongsToTenant, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'trial_ends_at' => 'immutable_datetime',
            'current_period_ends_at' => 'immutable_datetime',
            'cancel_at_period_end' => 'boolean',
            'last_advanced_at' => 'immutable_datetime',
        ];
    }

    /** The subscription of the organization in context. */
    public static function current(): self
    {
        return self::query()->with('plan')->sole();
    }

    public function nextPeriodStartsAt(): CarbonImmutable
    {
        return SaasInvoice::query()->where('subscription_id', $this->id)
            ->orderByDesc('period_ends_at')->lockForUpdate()->first()->period_ends_at
            ?? $this->current_period_ends_at ?? $this->trial_ends_at ?? now()->toImmutable();
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return HasMany<SubscriptionStateChange, $this> */
    public function stateChanges(): HasMany
    {
        return $this->hasMany(SubscriptionStateChange::class)->latest()->orderByDesc('id');
    }
}
