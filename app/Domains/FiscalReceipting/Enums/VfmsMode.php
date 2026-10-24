<?php

namespace App\Domains\FiscalReceipting\Enums;

/** Sandbox never leaves the server; Live files with ZRA's production VFMS. */
enum VfmsMode: string
{
    case Sandbox = 'sandbox';
    case Live = 'live';
}
