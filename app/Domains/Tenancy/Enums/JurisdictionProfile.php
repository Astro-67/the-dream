<?php

namespace App\Domains\Tenancy\Enums;

/**
 * The set of legal rules a property operates under (GLOSSARY: jurisdiction profile).
 */
enum JurisdictionProfile: string
{
    case Zanzibar = 'zanzibar';
    case MainlandTanzania = 'mainland_tanzania';

    public function label(): string
    {
        return match ($this) {
            self::Zanzibar => 'Zanzibar',
            self::MainlandTanzania => 'Mainland Tanzania',
        };
    }
}
