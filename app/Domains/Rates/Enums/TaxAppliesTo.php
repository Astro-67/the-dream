<?php

namespace App\Domains\Rates\Enums;

enum TaxAppliesTo: string
{
    case Room = 'room';
    case Extras = 'extras';
    case Both = 'both';

    public function covers(ChargeKind $kind): bool
    {
        return match ($this) {
            self::Both => true,
            self::Room => $kind === ChargeKind::Room,
            self::Extras => $kind === ChargeKind::Extra,
        };
    }
}
