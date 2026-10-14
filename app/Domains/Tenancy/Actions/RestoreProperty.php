<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Inventory\Models\Room;
use App\Domains\Subscriptions\Entitlements;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Brings an archived property back into operation: it returns to the switcher
 * and operational lists for the members who may act in it.
 */
class RestoreProperty
{
    public function handle(?User $actor, Property $property): Property
    {
        if ($property->archived_at === null) {
            return $property;
        }

        return Tenancy::within(TenantContext::forOrganization($property->organization_id), fn () => Audit::validationRefusal($actor, $property, 'Property restoration refused', fn () => DB::transaction(function () use ($actor, $property) {
            $entitlements = app(Entitlements::class);
            $subscription = $entitlements->lockForNewSales($property->demo);
            $property = Property::query()->whereKey($property->id)->lockForUpdate()->firstOrFail();
            if ($property->archived_at === null) {
                return $property;
            }

            $rooms = count(Room::query()->where('property_id', $property->id)->active()->lockForUpdate()->pluck('id')->all());
            $entitlements->assertCapacity($subscription, properties: 1, rooms: $rooms);
            $before = ['archived_at' => $property->archived_at->toIso8601String()];
            $property->archived_at = null;
            $property->save();

            Audit::record($actor, 'restored', $property, 'Property restored', [
                'property_id' => $property->getKey(),
                'old' => $before,
                'attributes' => ['archived_at' => null],
            ]);

            return $property;
        }, attempts: 3)));
    }
}
