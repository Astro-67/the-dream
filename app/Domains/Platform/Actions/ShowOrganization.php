<?php

namespace App\Domains\Platform\Actions;

use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Tenancy\Models\Organization;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;

final class ShowOrganization
{
    /** @return array<string, mixed> */
    public function handle(string $id): array
    {
        return Tenancy::within(TenantContext::platform('Platform administrator viewed organization '.$id), function () use ($id): array {
            $organization = Organization::query()->findOrFail($id);
            $subscription = Subscription::query()->where('organization_id', $id)->with('plan')->first();

            return [
                'organization' => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'owners' => app(ListOrganizations::class)->ownersOf([$id])[$id] ?? [],
                ],
                'subscription' => $subscription === null ? null : [
                    'plan' => $subscription->plan->name,
                    'status' => $subscription->status->value,
                    'statusLabel' => $subscription->status->label(),
                    'currentPeriodEndsAt' => $subscription->current_period_ends_at?->toIso8601String(),
                ],
            ];
        });
    }
}
