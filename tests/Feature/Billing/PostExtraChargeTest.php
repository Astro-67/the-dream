<?php

use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Rates\Actions\ChangeTaxRule;
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
            ->and($posting->tax_lines)->toEqual([['name' => 'VAT', 'kind' => 'percentage', 'inclusive' => false, 'rate' => 1800, 'per_person_night' => null, 'amount' => 90]])
            ->and($posting->tax_rule_ids)->toEqual([$vat->id])
            ->and($posting->actor_id)->toBe($owner->id);
    });
});

test('changing a tax rule after posting leaves the posting tax lines unchanged', function () {
    [$owner, , $property] = organizationWithOwner();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00', $property->timezone));
    inTenant($property, function () use ($owner) {
        $vat = app(CreateTaxRule::class)->handle($owner, extrasVat());
        $folio = Folio::query()->where('reservation_id', app(CreateReservation::class)->handle($owner, BookingForm::details($owner))->id)->sole();
        $posting = app(PostExtraCharge::class)->handle($owner, $folio, ['category' => 'laundry', 'quantity' => 1, 'unit_price' => 1000]);

        app(ChangeTaxRule::class)->handle($owner, $vat, extrasVat(['rate' => 2000, 'effective_from' => '2026-05-01']));

        $stored = Posting::query()->findOrFail($posting->id);
        expect($stored->amount)->toBe(1180)->and($stored->tax_lines[0]['rate'])->toBe(1800)->and($stored->tax_lines[0]['amount'])->toBe(180)
            ->and($stored->tax_rule_ids)->toEqual([$vat->id]);
    });
});

test('repeating a charge with the same key posts once', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $folio = Folio::query()->where('reservation_id', app(CreateReservation::class)->handle($owner, BookingForm::details($owner))->id)->sole();
        $charge = ['category' => 'minibar', 'quantity' => 1, 'unit_price' => 300, 'idempotency_key' => 'form-1'];

        $first = app(PostExtraCharge::class)->handle($owner, $folio, $charge);
        $again = app(PostExtraCharge::class)->handle($owner, $folio, $charge);
        app(PostExtraCharge::class)->handle($owner, $folio, [...$charge, 'idempotency_key' => 'form-2']);

        expect($again->id)->toBe($first->id)->and(Posting::query()->count())->toBe(2);
    });
});

test('postings cannot be updated or deleted, one by one or in bulk', function (string $operation) {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner, $operation) {
        $folio = Folio::query()->where('reservation_id', app(CreateReservation::class)->handle($owner, BookingForm::details($owner))->id)->sole();
        $posting = app(PostExtraCharge::class)->handle($owner, $folio, ['category' => 'other', 'quantity' => 1, 'unit_price' => 500]);
        $tamper = match ($operation) {
            'update' => fn () => $posting->update(['amount' => 1]),
            'delete' => fn () => $posting->delete(),
            'bulk update' => fn () => Posting::query()->whereKey($posting->id)->update(['amount' => 1]),
            'bulk delete' => fn () => Posting::query()->whereKey($posting->id)->delete(),
        };

        expect($tamper)->toThrow(LogicException::class, 'append-only');
        expect(Posting::query()->findOrFail($posting->id)->amount)->toBe(500);
    });
})->with(['update', 'delete', 'bulk update', 'bulk delete']);

test('no app code writes the postings table around the Posting model', function () {
    $hits = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'))) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && preg_match("/DB::table\\(\\s*'postings'/", (string) file_get_contents($file->getPathname()))) {
            $hits[] = $file->getPathname();
        }
    }
    expect($hits)->toBeEmpty();
});
