<?php

namespace App\Domains\FiscalReceipting\Enums;

/** Sandbox files with ZRA's shared test server; Live uses the property's production unit. */
enum VfmsMode: string
{
    case Sandbox = 'sandbox';
    case Live = 'live';
}
