<?php

namespace App\Domains\Subscriptions\Actions;

use App\Domains\Subscriptions\Enums\SubscriptionStatus;
use App\Domains\Subscriptions\Models\Subscription;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The only way a subscription's status changes: records the change with its
 * reason in the state history and the audit trail (SUB-01). Runs in the
 * caller's transaction when there is one.
 */
class TransitionSubscription
{
    /** @param array<string, mixed> $attributes other subscription fields changing with the status */
    public function handle(Subscription $subscription, SubscriptionStatus $to, string $reason, ?User $actor = null, array $attributes = []): Subscription
    {
        if (trim($reason) === '') {
            throw new LogicException('A subscription state change needs a reason.');
        }

        return DB::transaction(function () use ($subscription, $to, $reason, $actor, $attributes) {
            $locked = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            $locked->forceFill([...$attributes, 'status' => $to])->save();
            if ($from === $to) {
                return $locked;
            }

            $locked->stateChanges()->create(['from' => $from, 'to' => $to, 'reason' => $reason, 'actor_id' => $actor?->id]);
            Audit::record($actor, 'subscription_status_changed', $locked, 'Subscription status changed', [
                'old' => ['status' => $from->value], 'attributes' => ['status' => $to->value], 'reason' => $reason,
            ]);

            return $locked;
        });
    }
}
