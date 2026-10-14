<?php

namespace App\Domains\Subscriptions\Enums;

/** Whether a SaaS invoice is still owed. Overdue is not a status: it is an open invoice past its due date. */
enum SaasInvoiceStatus: string
{
    case Open = 'open';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Paid => __('Paid'),
        };
    }
}
