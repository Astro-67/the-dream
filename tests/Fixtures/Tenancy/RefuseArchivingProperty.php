<?php

namespace Tests\Fixtures\Tenancy;

use App\Domains\Tenancy\Contracts\PreArchiveCheck;
use App\Domains\Tenancy\Models\Property;

/**
 * A test-only pre-archive check that refuses one named property, the way a
 * later milestone's check refuses a property with future bookings.
 */
final class RefuseArchivingProperty implements PreArchiveCheck
{
    public static ?string $refusedPropertyId = null;

    public static string $reason = 'This property has future bookings.';

    public function blockingReason(Property $property): ?string
    {
        return $property->getKey() === self::$refusedPropertyId ? self::$reason : null;
    }
}
