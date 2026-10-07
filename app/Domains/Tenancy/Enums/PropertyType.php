<?php

namespace App\Domains\Tenancy\Enums;

enum PropertyType: string
{
    case Hotel = 'hotel';
    case Resort = 'resort';
    case Lodge = 'lodge';
    case GuestHouse = 'guest_house';
    case Apartments = 'apartments';
    case Villa = 'villa';
    case Hostel = 'hostel';
    case Camp = 'camp';

    public function label(): string
    {
        return match ($this) {
            self::Hotel => 'Hotel',
            self::Resort => 'Resort',
            self::Lodge => 'Lodge',
            self::GuestHouse => 'Guest house',
            self::Apartments => 'Serviced apartments',
            self::Villa => 'Villa',
            self::Hostel => 'Hostel',
            self::Camp => 'Camp',
        };
    }
}
