<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Enums\PropertyStatus;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeclineGoLive
{
    public function forPlatform(User $actor, string $propertyId, string $reason): Property
    {
        $property = Tenancy::within(TenantContext::platform('Platform administrator declined a go-live request'),
            fn () => Property::query()->findOrFail($propertyId));

        return Tenancy::within(TenantContext::forOrganization($property->organization_id),
            fn () => $this->handle($actor, $property, $reason));
    }

    public function handle(User $actor, Property $property, string $reason): Property
    {
        return Audit::validationRefusal($actor, $property, 'Go-live decline refused', fn () => DB::transaction(function () use ($actor, $property, $reason) {
            $locked = Property::query()->whereKey($property->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PropertyStatus::Setup || $locked->archived_at !== null || $locked->go_live_requested_at === null) {
                throw ValidationException::withMessages(['reason' => __('There is no waiting go-live request for this property.')]);
            }

            $locked->forceFill([
                'go_live_requested_at' => null,
                'go_live_requested_by' => null,
                'go_live_declined_reason' => $reason,
            ])->save();
            Audit::record($actor, 'go_live_declined', $locked, 'Go-live request declined', [
                'property_id' => $locked->id,
                'reason' => $reason,
            ]);

            return $locked;
        }, attempts: 3));
    }
}
