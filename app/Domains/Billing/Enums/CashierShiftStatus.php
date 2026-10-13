<?php

namespace App\Domains\Billing\Enums;

enum CashierShiftStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
