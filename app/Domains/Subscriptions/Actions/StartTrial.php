<?php

namespace App\Domains\Subscriptions\Actions;

use App\Domains\Subscriptions\Enums\SubscriptionStatus;
use App\Domains\Subscriptions\Models\Plan;
use App\Domains\Subscriptions\Models\Subscription;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Starts the organization in context on a trial of the default plan (REG-02).
 * Called by CreateOrganization; a second call returns the existing subscription.
 */
class StartTrial
{
    public function handle(?User $actor, int $rooms = 1): Subscription
    {
        return DB::transaction(function () use ($actor, $rooms) {
            $existing = Subscription::query()->first();
            if ($existing !== null) {
                return $existing;
            }

            $plan = Plan::default();
            $subscription = Subscription::query()->create([
                'plan_id' => $plan->id,
                'rooms' => $rooms,
                'status' => SubscriptionStatus::Trial,
                'trial_ends_at' => now()->addDays($plan->trial_days),
            ]);
            $subscription->stateChanges()->create(['from' => null, 'to' => SubscriptionStatus::Trial, 'reason' => __('Trial started with the organization'), 'actor_id' => $actor?->id]);
            Audit::record($actor, 'subscription_started', $subscription, 'Subscription trial started', ['attributes' => ['plan' => $plan->code, 'rooms' => $rooms, 'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String()]]);

            return $subscription;
        });
    }
}
