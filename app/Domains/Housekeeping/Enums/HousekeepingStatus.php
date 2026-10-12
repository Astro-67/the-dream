<?php

namespace App\Domains\Housekeeping\Enums;

enum HousekeepingStatus: string
{
    case Dirty = 'dirty';
    case Clean = 'clean';
    case Occupied = 'occupied';
    case InspectionRequired = 'inspection_required';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Dirty => __('Dirty'),
            self::Clean => __('Clean'),
            self::Occupied => __('Occupied'),
            self::InspectionRequired => __('Inspection required'),
            self::Unavailable => __('Unavailable'),
        };
    }
}
