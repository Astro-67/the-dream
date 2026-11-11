<?php

namespace App\Domains\Reservations\Enums;

use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;

enum PayingInstitutionType: string
{
    case Government = 'government';
    case Business = 'business';

    /** Only properties that file real stays with VFMS record a paying institution; Mainland and demo properties never do. */
    public static function offeredAt(Property $property): bool
    {
        return ! $property->demo && JurisdictionRules::usesVfms($property);
    }

    /** ZRA's `accommodationType` value; case-sensitive on the wire. */
    public function vfmsValue(): string
    {
        return match ($this) {
            self::Government => 'WITHHOLD',
            self::Business => 'BTOB',
        };
    }
}
