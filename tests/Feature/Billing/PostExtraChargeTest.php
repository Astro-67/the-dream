<?php

use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Models\Folio;
use App\Domains\Rates\Actions\CreateTaxRule;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Support\Money\Currency;
use Carbon\CarbonImmutable;
use Tests\Support\BookingForm;

/** @return array{name: string, kind: string, rate: int|null, amount: int|null, currency: string|null, inclusive: bool, applies_to: string, rounding: string, effective_from: string, effective_to: string|null} */
function extrasVat(array $terms = []): array
{
    return ['name' => 'VAT', 'kind' => 'percentage', 'rate' => 1800, 'amount' => null, 'currency' => null, 'inclusive' => false, 'applies_to' => 'extras', 'rounding' => 'half_up', 'effective_from' => '2026-01-01', 'effective_to' => null, ...$terms];
}

test('an extra charge stores quantity, unit price, amount, tax lines and the rule versions used', function () {
    [$owner, , $property] = organizationWithOwner();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00', $property->timezone));
    inTenant($property, function () use ($owner) {
        $vat = app(CreateTaxRule::class)->handle($owner, extrasVat());
        $folio = Folio::query()->where('reservation_id', app(CreateReservation::class)->handle($owner, BookingForm::details($owner))->id)->sole();

        $posting = app(PostExtraCharge::class)->handle($owner, $folio, ['category' => 'minibar', 'description' => 'Two sodas', 'quantity' => 2, 'unit_price' => 250]);

        expect($posting->only(['type', 'category', 'description', 'quantity', 'unit_price', 'net', 'amount']))
            ->toBe(['type' => 'charge', 'category' => 'minibar', 'description' => 'Two sodas', 'quantity' => 2, 'unit_price' => 250, 'net' => 500, 'amount' => 590])
            ->and($posting->currency)->toBe(Currency::USD)
            ->and($posting->tax_lines)->toBe([['name' => 'VAT', 'kind' => 'percentage', 'inclusive' => false, 'rate' => 1800, 'per_person_night' => null, 'amount' => 90]])
            ->and($posting->tax_rule_ids)->toBe([$vat->id])
            ->and($posting->actor_id)->toBe($owner->id);
    });
});
