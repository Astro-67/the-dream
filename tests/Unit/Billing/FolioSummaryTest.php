<?php

use App\Domains\Billing\FolioSummary;
use App\Domains\Billing\Models\Posting;
use App\Domains\Rates\Enums\ChargeKind;
use App\Domains\Rates\Models\TaxRule;
use App\Domains\Rates\TaxCalculator;
use App\Support\Money\Currency;
use Tests\TestCase;

uses(TestCase::class);

/**
 * An unsaved USD posting.
 *
 * @param  array<string, mixed>  $attributes
 */
function summaryPosting(string $type, string $category, int $amount, array $attributes = []): Posting
{
    return (new Posting)->forceFill(['type' => $type, 'category' => $category, 'net' => $amount, 'amount' => $amount, 'currency' => 'USD', 'tax_lines' => [], ...$attributes]);
}

/** A USD charge split by the calculator under Zanzibar's defaults: 15% VAT included, USD 5 levy added on rooms. */
function summaryCharge(string $category, int $price, ChargeKind $kind): Posting
{
    $rules = [
        (new TaxRule)->forceFill(['zra_tax_category' => 'vat', 'name' => 'VAT', 'kind' => 'percentage', 'rate' => 1500, 'amount' => null, 'currency' => null, 'inclusive' => true, 'applies_to' => 'both', 'rounding' => 'half_up', 'effective_from' => '2026-01-01', 'effective_to' => null]),
        (new TaxRule)->forceFill(['zra_tax_category' => 'hotel_levy', 'name' => 'Infrastructure levy', 'kind' => 'flat_per_person_night', 'rate' => null, 'amount' => 500, 'currency' => 'USD', 'inclusive' => false, 'applies_to' => 'room', 'rounding' => 'half_up', 'effective_from' => '2026-01-01', 'effective_to' => null]),
    ];
    $tax = TaxCalculator::breakdown($rules, $price, Currency::USD, '2026-06-01', 1, $kind);

    return summaryPosting('charge', $category, $tax->gross(), ['net' => $tax->net, 'tax_lines' => $tax->lines]);
}

/** @param array<string, mixed> $summary */
function reconciles(array $summary): bool
{
    $charges = $summary['room'] + $summary['extras'] + $summary['vat'] + $summary['levy'] + $summary['other_taxes'] + $summary['unclassified_taxes'];

    return $charges === $summary['charges'] && $summary['balance'] === $summary['charges'] - $summary['net_payments'] - $summary['written_off'];
}

// protects=the posted ledger reconciles to the spec's example; fails_when=VAT is added to an inclusive price twice.
test('the spec example: an inclusive room, an added levy and an inclusive extra make 115.00, and 100.00 paid leaves 15.00', function () {
    $summary = FolioSummary::of([
        summaryCharge('room', 10000, ChargeKind::Room),
        summaryCharge('minibar', 1000, ChargeKind::Extra),
        summaryPosting('payment', 'payment', -10000, ['net' => 10000]),
    ]);

    expect($summary)->toBe([
        'room' => 8696, 'extras' => 870, 'vat' => 1434, 'levy' => 500, 'other_taxes' => 0, 'unclassified_taxes' => 0,
        'charges' => 11500, 'paid' => 10000, 'refunded' => 0, 'written_off' => 0, 'net_payments' => 10000, 'balance' => 1500, 'levy_unavailable' => false,
    ])->and(reconciles($summary))->toBeTrue();
});

// protects=corrections cancel once; fails_when=a reversed charge, a refunded payment or a transfer counts twice.
test('reversals, transfers, refunds and write-offs reconcile without counting anything twice', function () {
    $room = summaryCharge('room', 10000, ChargeKind::Room);
    $negative = fn (Posting $posting, string $type) => summaryPosting($type, $posting->category, -$posting->amount, [
        'net' => -$posting->net, 'tax_lines' => array_map(fn (array $line) => [...$line, 'amount' => -$line['amount']], $posting->tax_lines),
    ]);
    $laundry = summaryCharge('laundry', 2000, ChargeKind::Extra);
    $payment = summaryPosting('payment', 'payment', -9000, ['net' => 9000]);

    $source = FolioSummary::of([$room, $laundry, $negative($laundry, 'reversal'), $negative($room, 'transfer_out'), $payment, $negative($payment, 'reversal')]);
    $target = FolioSummary::of([$negative($negative($room, 'transfer_out'), 'transfer_in'), summaryPosting('payment', 'deposit', -12000, ['net' => 12000]),
        summaryPosting('refund', 'refund', 1000), summaryPosting('write_off', 'write_off', -500, ['net' => -500])]);

    expect($source)->toMatchArray(['charges' => 0, 'vat' => 0, 'levy' => 0, 'paid' => 0, 'balance' => 0])
        ->and($target)->toMatchArray(['room' => 8696, 'vat' => 1304, 'levy' => 500, 'charges' => 10500, 'paid' => 12000, 'refunded' => 1000, 'net_payments' => 11000, 'written_off' => 500, 'balance' => -1000])
        ->and(reconciles($source))->toBeTrue()->and(reconciles($target))->toBeTrue();
});

// protects=legacy lines keep their amounts; fails_when=a translated name is guessed into VAT or the levy.
test('tax lines stored before categories stay unclassified, and uncategorised rules count as other taxes', function () {
    $summary = FolioSummary::of([summaryPosting('charge', 'room', 11800, ['net' => 10000, 'tax_lines' => [
        ['name' => 'VAT', 'kind' => 'percentage', 'inclusive' => false, 'rate' => 1500, 'per_person_night' => null, 'amount' => 1500],
        ['name' => 'Service charge', 'category' => null, 'kind' => 'percentage', 'inclusive' => false, 'rate' => 300, 'per_person_night' => null, 'amount' => 300],
    ]])]);

    expect($summary)->toMatchArray(['room' => 10000, 'vat' => 0, 'other_taxes' => 300, 'unclassified_taxes' => 1500, 'charges' => 11800])
        ->and(reconciles($summary))->toBeTrue();
});
