<?php

namespace App\Domains\Reservations\Enums;

enum PayingInstitutionType: string
{
    case Government = 'government';
    case Business = 'business';

    /** ZRA's `accommodationType` value; case-sensitive on the wire. */
    public function vfmsValue(): string
    {
        return match ($this) {
            self::Government => 'WITHHOLD',
            self::Business => 'BTOB',
        };
    }
}
