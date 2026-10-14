<?php

namespace App\Domains\Subscriptions;

use App\Domains\Inventory\Models\Room;
use App\Domains\Tenancy\Models\Property;

/**
 * What the organization in context uses against its plan limits: active real
 * properties and their active rooms.
 */
final class PlanUsage
{
    /** @return array{properties: int, rooms: int} */
    public static function current(): array
    {
        $properties = Property::query()->active()->real()->pluck('id');

        return [
            'properties' => $properties->count(),
            'rooms' => Room::query()->whereIn('property_id', $properties)->active()->count(),
        ];
    }
}
