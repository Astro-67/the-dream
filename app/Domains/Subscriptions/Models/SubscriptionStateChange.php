<?php

namespace App\Domains\Subscriptions\Models;

use App\Domains\Subscriptions\Enums\SubscriptionStatus;
use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One subscription status change and why it happened, for tracing billing disputes.
 *
 * @property string $id
 * @property SubscriptionStatus|null $from
 * @property SubscriptionStatus $to
 * @property string $reason
 * @property string|null $actor_id
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['subscription_id', 'from', 'to', 'reason', 'actor_id'])]
class SubscriptionStateChange extends Model
{
    use BelongsToTenant, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['from' => SubscriptionStatus::class, 'to' => SubscriptionStatus::class, 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
