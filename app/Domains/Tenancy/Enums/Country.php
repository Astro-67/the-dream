<?php

namespace App\Domains\Tenancy\Enums;

/**
 * Countries an organization can be registered in (ISO 3166-1 alpha-2).
 * Tanzania first; the rest cover the region and common owners' home countries.
 */
enum Country: string
{
    case Tanzania = 'TZ';
    case Kenya = 'KE';
    case Uganda = 'UG';
    case Rwanda = 'RW';
    case Burundi = 'BI';
    case SouthAfrica = 'ZA';
    case UnitedArabEmirates = 'AE';
    case Oman = 'OM';
    case India = 'IN';
    case UnitedKingdom = 'GB';
    case Germany = 'DE';
    case Italy = 'IT';
    case France = 'FR';
    case Netherlands = 'NL';
    case UnitedStates = 'US';

    public function label(): string
    {
        return match ($this) {
            self::Tanzania => 'Tanzania',
            self::Kenya => 'Kenya',
            self::Uganda => 'Uganda',
            self::Rwanda => 'Rwanda',
            self::Burundi => 'Burundi',
            self::SouthAfrica => 'South Africa',
            self::UnitedArabEmirates => 'United Arab Emirates',
            self::Oman => 'Oman',
            self::India => 'India',
            self::UnitedKingdom => 'United Kingdom',
            self::Germany => 'Germany',
            self::Italy => 'Italy',
            self::France => 'France',
            self::Netherlands => 'Netherlands',
            self::UnitedStates => 'United States',
        };
    }
}
