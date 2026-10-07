<?php

namespace App\Support\Money;

/**
 * Currencies a property may operate in (PRD §6).
 */
enum Currency: string
{
    case TZS = 'TZS';
    case USD = 'USD';
    case EUR = 'EUR';
}
