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

test('rounding up preserves an exact integer after removing inclusive tax', function () {
    $rules = [taxRule(['inclusive' => true]), taxRule(['name' => 'Levy', 'rate' => 7670, 'rounding' => 'up'])];

    // 20 * 7670 / 11800 = 13 exactly, not a floating-point value above 13.
    expect(taxLines(TaxCalculator::breakdown($rules, 20, Currency::USD, '2026-06-01', 1, ChargeKind::Room)))
        ->toBe([['VAT', 3], ['Levy', 13]]);
});

test('an exclusive percentage is added on top of the amount', function () {
    $breakdown = TaxCalculator::breakdown([taxRule()], 10000, Currency::USD, '2026-06-01', 2, ChargeKind::Room);

    expect([$breakdown->net, $breakdown->tax(), $breakdown->gross()])->toBe([10000, 1800, 11800])
        ->and(taxLines($breakdown))->toBe([['VAT', 1800]]);
});

test('an inclusive percentage is taken out of the amount, leaving the net', function () {
    $breakdown = TaxCalculator::breakdown([taxRule(['inclusive' => true])], 11800, Currency::USD, '2026-06-01', 2, ChargeKind::Room);
    expect([$breakdown->net, $breakdown->tax(), $breakdown->gross()])->toBe([10000, 1800, 11800]);

    // 10000 / 1.18 = 8474.58 net; the line rounds to 1525 and the remainder stays in the net.
    $breakdown = TaxCalculator::breakdown([taxRule(['inclusive' => true])], 10000, Currency::USD, '2026-06-01', 2, ChargeKind::Room);
    expect([$breakdown->net, $breakdown->tax(), $breakdown->gross()])->toBe([8475, 1525, 10000]);
});

test('a flat per-person-per-night rule charges each guest and works alongside a percentage', function () {
    $levy = taxRule(['name' => 'Infrastructure tax', 'kind' => 'flat_per_person_night', 'rate' => null, 'amount' => 100, 'currency' => 'USD', 'applies_to' => 'room']);

    // The percentage applies to the room price, not to the levy.
    $breakdown = TaxCalculator::breakdown([taxRule(), $levy], 10000, Currency::USD, '2026-06-01', 3, ChargeKind::Room);
    expect([$breakdown->net, $breakdown->tax(), $breakdown->gross()])->toBe([10000, 2100, 12100])
        ->and(taxLines($breakdown))->toBe([['VAT', 1800], ['Infrastructure tax', 300]]);

    // Both inside the price: the levy comes off first, then VAT out of the rest.
    $breakdown = TaxCalculator::breakdown([taxRule(['inclusive' => true]), $levy->forceFill(['inclusive' => true])], 12100, Currency::USD, '2026-06-01', 3, ChargeKind::Room);
    expect([$breakdown->net, $breakdown->tax(), $breakdown->gross()])->toBe([10000, 2100, 12100]);
});

test('a flat rule in another currency is refused rather than converted', function () {
    $levy = taxRule(['name' => 'Infrastructure tax', 'kind' => 'flat_per_person_night', 'rate' => null, 'amount' => 100, 'currency' => 'USD']);

    expect(fn () => TaxCalculator::breakdown([$levy], 250000, Currency::TZS, '2026-06-01', 2, ChargeKind::Room))->toThrow(InvalidArgumentException::class);
});

test('each line rounds to minor units by its rule', function (string $rounding, int $tax) {
    // 12.5% of 1001 is 125.125; 12.5% of 1004 is 125.5.
    $rule = taxRule(['rate' => 1250, 'rounding' => $rounding]);

    expect(TaxCalculator::breakdown([$rule], 1001, Currency::TZS, '2026-06-01', 1, ChargeKind::Room)->tax()
        + TaxCalculator::breakdown([$rule], 1004, Currency::TZS, '2026-06-01', 1, ChargeKind::Room)->tax())->toBe($tax);
})->with([
    'half up' => ['half_up', 125 + 126],
    'up' => ['up', 126 + 126],
    'down' => ['down', 125 + 125],
]);

test('a rule applies only from its first to its last effective date, so a change leaves earlier dates alone', function () {
    $rules = [
        taxRule(['effective_to' => '2026-06-30']),
        taxRule(['rate' => 2000, 'effective_from' => '2026-07-01']),
    ];
    $tax = fn (string $date) => TaxCalculator::breakdown($rules, 10000, Currency::USD, $date, 2, ChargeKind::Room)->tax();

    expect([$tax('2025-12-31'), $tax('2026-01-01'), $tax('2026-06-30'), $tax('2026-07-01'), $tax('2030-01-01')])
        ->toBe([0, 1800, 1800, 2000, 2000]);
});

test('a rule covers room charges, extras or both', function () {
    $rules = [taxRule(['name' => 'VAT']), taxRule(['name' => 'Service charge', 'rate' => 1000, 'applies_to' => 'room']), taxRule(['name' => 'Extras levy', 'rate' => 500, 'applies_to' => 'extras'])];

    expect(taxLines(TaxCalculator::breakdown($rules, 10000, Currency::USD, '2026-06-01', 2, ChargeKind::Room)))->toBe([['VAT', 1800], ['Service charge', 1000]])
        ->and(taxLines(TaxCalculator::breakdown($rules, 10000, Currency::USD, '2026-06-01', 2, ChargeKind::Extra)))->toBe([['VAT', 1800], ['Extras levy', 500]]);
});

/** The seeded pair: 15% VAT on rooms and extras, and the USD 5 levy per guest-night on rooms. */
function seededTaxRules(bool $vatIncluded, bool $levyIncluded): array
{
    return [
        taxRule(['rate' => 1500, 'inclusive' => $vatIncluded, 'zra_tax_category' => 'vat']),
        taxRule(['name' => 'Infrastructure levy', 'kind' => 'flat_per_person_night', 'rate' => null, 'amount' => 500, 'currency' => 'USD',
            'inclusive' => $levyIncluded, 'applies_to' => 'room', 'zra_tax_category' => 'hotel_levy']),
    ];
}

// protects=exact USD lines for each VAT/levy inclusion; fails_when=VAT is added to an already-inclusive price or the levy is taxed; no_claim=VFMS assessment.
test('every VAT and levy inclusion combination reconciles to the price and its lines', function (bool $vat, bool $levy, int $guests, array $expected) {
    $breakdown = TaxCalculator::breakdown(seededTaxRules($vat, $levy), 10000, Currency::USD, '2026-06-01', $guests, ChargeKind::Room);

    expect([$breakdown->net, ...array_column(taxLines($breakdown), 1), $breakdown->gross()])->toBe($expected)
        ->and($breakdown->net + $breakdown->tax())->toBe($breakdown->gross());
})->with([
    // 10000 / 1.15 = 8695.65 net; VAT 1304.35 rounds to 1304 and the remainder stays in the net.
    'VAT included, levy added' => [true, false, 1, [8696, 1304, 500, 10500]],
    'both added' => [false, false, 1, [10000, 1500, 500, 12000]],
    // The levy comes off first: (10000 - 1000) / 1.15 = 7826.09 net.
    'both included, two guests' => [true, true, 2, [7826, 1174, 1000, 10000]],
    'VAT added, levy included, two guests' => [false, true, 2, [9000, 1350, 1000, 11350]],
]);

test('a zero price and zero levy give zero tax', function () {
    $rules = seededTaxRules(true, true);
    $rules[1]->amount = 0;

    expect(TaxCalculator::breakdown($rules, 0, Currency::USD, '2026-06-01', 2, ChargeKind::Room)->gross())->toBe(0);
});

// protects=no posting below zero net; fails_when=included levies exceed the price and the net goes negative or clamps silently.
test('included flat levies above the price are refused, not clamped', function () {
    expect(fn () => TaxCalculator::breakdown(seededTaxRules(true, true), 900, Currency::USD, '2026-06-01', 2, ChargeKind::Room))
        ->toThrow(InvalidArgumentException::class);

    // Rounding inclusive percentages up on what remains can also take the net below zero.
    $rules = [...seededTaxRules(false, true), taxRule(['name' => 'A', 'rate' => 10000, 'inclusive' => true, 'rounding' => 'up']), taxRule(['name' => 'B', 'rate' => 10000, 'inclusive' => true, 'rounding' => 'up'])];
    expect(fn () => TaxCalculator::breakdown($rules, 1001, Currency::USD, '2026-06-01', 2, ChargeKind::Room))->toThrow(InvalidArgumentException::class);
});

// protects=TZS amounts never carry a converted USD levy; fails_when=an invented exchange rate or a silent zero line appears; no_claim=actual VFMS FX.
test('the USD levy on a TZS charge is reported as unavailable, not converted', function () {
    $breakdown = TaxCalculator::breakdown(seededTaxRules(true, false), 250000, Currency::TZS, '2026-06-01', 2, ChargeKind::Room);

    expect(taxLines($breakdown))->toBe([['VAT', 32609]])->and($breakdown->unavailable)->toBe(['Infrastructure levy']);
});
