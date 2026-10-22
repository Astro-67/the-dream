<?php

namespace App\Domains\Maintenance\Enums;

enum TicketCategory: string
{
    case Repair = 'repair';
    case Preventive = 'preventive';
    case Corrective = 'corrective';

    public function label(): string
    {
        return match ($this) {
            self::Repair => __('Repair'),
            self::Preventive => __('Preventive'),
            self::Corrective => __('Corrective'),
        };
    }
}
