<?php

namespace App\Domains\Rates\Data;

use App\Support\Money\Currency;

/**
 * A stay's nightly prices in integer minor units of one currency, or the reason it cannot be quoted.
 */
final readonly class StayQuote
{
    /**
     * @param  list<array{date: string, base_price: int, extra_adults: int, extra_children: int, total: int}>  $nights
     */
    public function __construct(
        public Currency $currency,
        public array $nights,
        public ?string $unquotable = null,
    ) {}

    public function total(): int
    {
        return array_sum(array_column($this->nights, 'total'));
    }
}
