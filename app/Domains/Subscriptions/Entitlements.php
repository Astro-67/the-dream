<?php

namespace App\Domains\Subscriptions;

use App\Domains\Subscriptions\Models\Subscription;
use Illuminate\Validation\ValidationException;

final class Entitlements
{
    public function lockForNewSales(bool $demo = false): ?Subscription
    {
        $subscription = Subscription::query()->lockForUpdate()->sole();
        if ($subscription->status->blocksNewSales()) {
            throw ValidationException::withMessages([
                'subscription' => __('Your subscription is :status. New reservations, rooms and properties are unavailable. Existing guest operations and exports remain available.', [
                    'status' => $subscription->status->label(),
                ]),
            ]);
        }

        return $demo ? null : $subscription;
    }

    public function assertCapacity(?Subscription $subscription, int $properties = 0, int $rooms = 0): void
    {
        if ($subscription === null) {
            return;
        }

        $usage = PlanUsage::current(lock: true);
        $plan = $subscription->plan;
        if ($properties > 0 && $plan->property_limit < $usage['properties'] + $properties) {
            throw ValidationException::withMessages([
                'property' => __('Your plan allows :limit active properties. Archive a property or change your plan before adding another.', ['limit' => $plan->property_limit]),
            ]);
        }
        if ($rooms > 0 && $plan->room_limit < $usage['rooms'] + $rooms) {
            throw ValidationException::withMessages([
                'room' => __('Your plan allows :limit active rooms. Deactivate rooms or change your plan before adding more.', ['limit' => $plan->room_limit]),
            ]);
        }
    }
}
