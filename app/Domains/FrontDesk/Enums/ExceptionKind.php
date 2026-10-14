<?php

namespace App\Domains\FrontDesk\Enums;

/** What an exception list item points at (glossary: Exception list). */
enum ExceptionKind: string
{
    case NoShow = 'no_show';
    case Departure = 'departure';
    case CashierShift = 'cashier_shift';
}
