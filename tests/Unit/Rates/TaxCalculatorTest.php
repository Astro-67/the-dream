<?php

use App\Domains\Rates\Data\TaxBreakdown;
use App\Domains\Rates\Enums\ChargeKind;
use App\Domains\Rates\Models\TaxRule;
use App\Domains\Rates\TaxCalculator;
use App\Support\Money\Currency;
use Tests\TestCase;

uses(TestCase::class);

/**
 * An unsaved rule: 18% exclusive VAT on rooms and extras from 2026-01-01, unless overridden.
 *
 * @param  array<string, mixed>  $terms
 */
function taxRule(array $terms = []): TaxRule
{
    return (new TaxRule)->forceFill([
        'name' => 'VAT', 'kind' => 'percentage', 'rate' => 1800, 'amount' => null, 'currency' => null,
        'inclusive' => false, 'applies_to' => 'both', 'rounding' => 'half_up',
        'effective_from' => '2026-01-01', 'effective_to' => null, ...$terms,
    ]);
}

/** @return list<array{0: string, 1: int}> */
function taxLines(TaxBreakdown $breakdown): array
{
    return array_map(fn (array $line) => [$line['name'], $line['amount']], $breakdown->lines);
}

test('an exclusive percentage is added on top of the amount', function () {
    $breakdown = TaxCalculator::breakdown([taxRule()], 10000, Currency::USD, '2026-06-01', 2, ChargeKind::Room);

    expect([$breakdown->net, $breakdown->tax(), $breakdown->gross()])->toBe([10000, 1800, 11800])
        ->and(taxLines($breakdown))->toBe([['VAT', 1800]]);
});
