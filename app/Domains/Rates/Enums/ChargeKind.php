<?php

namespace App\Domains\Rates\Enums;

/**
 * What a charge is for, so a tax rule can apply to room charges, extras or both.
 */
enum ChargeKind: string
{
    case Room = 'room';
    case Extra = 'extra';
}
