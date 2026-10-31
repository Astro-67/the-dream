<?php

namespace App\Domains\Platform\Actions;

use App\Domains\Subscriptions\Enums\SubscriptionStatus;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Tenancy\Actions\GoLive;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A platform administrator approves a property's waiting go-live request (GLOSSARY: go-live), in the property's
 * organization. Refused without a waiting request, unless the subscription is Active, and while any gate fails.
 * Locks the subscription before the property, matching the hourly lifecycle and entitlements.
 */
final class ApproveGoLive
{
    public function handle(User $actor, string $propertyId): Property
    {
        $property = Tenancy::within(TenantContext::platform('Platform administrator approved go-live for property '.$propertyId),
            fn () => Property::query()->findOrFail($propertyId));

        return Tenancy::within(TenantContext::forProperty($property), fn () => Audit::validationRefusal($actor, $property, 'Go-live approval refused', fn () => DB::transaction(function () use ($actor, $property) {
            $subscription = Subscription::query()->lockForUpdate()->sole();
            $locked = Property::query()->whereKey($property->id)->lockForUpdate()->firstOrFail();
            if ($locked->isLive() || $locked->go_live_requested_at === null) {
                throw ValidationException::withMessages(['go_live' => __('This property has no go-live request waiting.')]);
            }
            if ($subscription->status !== SubscriptionStatus::Active) {
                throw ValidationException::withMessages(['go_live' => __('The subscription is :status. Go-live needs an Active subscription.', ['status' => $subscription->status->label()])]);
            }

            GoLive::ensureReady($locked);

            return app(GoLive::class)->handle($actor, $locked);
        }, attempts: 3)));
    }
}
