<?php

namespace App\Support\Money;

use InvalidArgumentException;

/**
 * An amount in integer minor units of one currency. Never converted: adding
 * amounts in different currencies is a bug, so it throws (FIN-01).
 */
final readonly class Money
{
    public function __construct(
        public int $amount,
        public Currency $currency,
    ) {}

    public function plus(self $other): self
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException("Cannot add {$other->currency->value} to {$this->currency->value}.");
        }

        return new self($this->amount + $other->amount, $this->currency);
    }
}
