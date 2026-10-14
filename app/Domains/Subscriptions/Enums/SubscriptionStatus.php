<?php

namespace App\Domains\Subscriptions\Enums;

/**
 * Where an organization's subscription stands (PRD SUB-01).
 */
enum SubscriptionStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case PastDue = 'past_due';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Trial => __('Trial'),
            self::Active => __('Active'),
            self::PastDue => __('Past due'),
            self::Suspended => __('Suspended'),
            self::Cancelled => __('Cancelled'),
        };
    }

    /** Suspended and cancelled organizations can't make new sales or grow (SUB-04). */
    public function blocksNewSales(): bool
    {
        return in_array($this, [self::Suspended, self::Cancelled], true);
    }
}
