<?php

namespace App\Domains\Rates\Enums;

enum TaxKind: string
{
    /** A rate in basis points of the charge (1800 is 18%). */
    case Percentage = 'percentage';

    /** A fixed amount in minor units of its own currency, per guest per night. */
    case FlatPerPersonNight = 'flat_per_person_night';
}
