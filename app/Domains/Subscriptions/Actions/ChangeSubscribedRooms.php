<?php

namespace App\Domains\Subscriptions\Actions;

use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\PlanUsage;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes how many rooms the organization in context subscribes to (ADR 0018).
 * Takes effect at once for the room cap and from the next SaaS invoice for the
 * price; never below the rooms already active.
 */
class ChangeSubscribedRooms
{
    public function handle(?User $actor, int $rooms): Subscription
    {
        $subscription = Subscription::current();

        return Audit::validationRefusal($actor, $subscription, 'Subscribed rooms change refused', fn () => DB::transaction(function () use ($actor, $rooms) {
            $subscription = Subscription::query()->lockForUpdate()->sole();
            $active = PlanUsage::current(lock: true)['rooms'];
            if ($rooms < $active) {
                throw ValidationException::withMessages(['rooms' => __(':active rooms are active. Deactivate rooms before subscribing to fewer.', ['active' => $active])]);
            }

            $old = $subscription->rooms;
            $subscription->update(['rooms' => $rooms]);
            Audit::record($actor, 'subscribed_rooms_changed', $subscription, 'Subscribed rooms changed', ['old' => ['rooms' => $old], 'attributes' => ['rooms' => $rooms]]);

            return $subscription;
        }));
    }
}
