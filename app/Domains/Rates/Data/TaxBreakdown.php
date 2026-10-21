<?php

namespace App\Domains\Rates\Data;

use App\Support\Money\Currency;

/**
 * A charge split into its net amount and tax lines, in integer minor units of one
 * currency. net plus every line is gross. Spec 6 stores it on the posted charge.
 */
final readonly class TaxBreakdown
{
    /**
     * @param  list<array{name: string, kind: string, inclusive: bool, rate: int|null, per_person_night: int|null, amount: int}>  $lines
     * @param  list<string>  $ruleIds  the tax rule versions that gave the lines, in line order
     * @param  list<string>  $unavailable  names of taxes left out because they are not available in this currency
     */
    public function __construct(
        public Currency $currency,
        public int $net,
        public array $lines,
        public array $ruleIds = [],
        public array $unavailable = [],
    ) {}

    public function tax(): int
    {
        return array_sum(array_column($this->lines, 'amount'));
    }

    public function gross(): int
    {
        return $this->net + $this->tax();
    }
}
