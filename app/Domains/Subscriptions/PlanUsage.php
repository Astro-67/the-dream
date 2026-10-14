<?php

namespace App\Domains\Subscriptions;

use App\Domains\Inventory\Models\Room;
use App\Domains\Tenancy\Models\Property;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;

/**
 * What the organization in context uses against its plan limits: active real
 * properties and their active rooms.
 */
final class PlanUsage
{
    /** @return array{properties: int, rooms: int} */
    public static function current(bool $lock = false): array
    {
        $organization = Tenancy::organization() ?? throw MissingTenantContext::forQuery(Property::class);

        return Tenancy::within(TenantContext::forOrganization($organization), function () use ($lock) {
            $properties = Property::query()->active()->real()->when($lock, fn ($query) => $query->lockForUpdate())->pluck('id');
            $rooms = Room::query()->whereIn('property_id', $properties)->active();

            return [
                'properties' => $properties->count(),
                'rooms' => $lock ? count($rooms->lockForUpdate()->pluck('id')->all()) : $rooms->count(),
            ];
        });
    }
}
