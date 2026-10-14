<?php

namespace App\Domains\Subscriptions\Enums;

/** How an organization paid Thedream; online payment through a provider is out of scope for M2. */
enum SaasPaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case MobileMoney = 'mobile_money';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => __('Bank transfer'),
            self::MobileMoney => __('Mobile money'),
        };
    }
}
