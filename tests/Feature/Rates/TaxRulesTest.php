<?php

use App\Domains\Rates\Enums\ChargeKind;
use App\Domains\Rates\Models\TaxRule;
use App\Domains\Rates\TaxCalculator;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Support\Money\Currency;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/** @return array<string, mixed> 18% exclusive VAT on rooms and extras from 2026-01-01, posted to $propertyId, unless overridden. */
function vatForm(string $propertyId, array $terms = []): array
{
    return [
        '_property' => $propertyId, 'name' => 'VAT', 'kind' => 'percentage', 'rate' => '18',
        'inclusive' => '0', 'applies_to' => 'both', 'rounding' => 'half_up', 'effective_from' => '2026-01-01', ...$terms,
    ];
}

test('a finance user adds a tax rule, sees it listed, and the addition is audited', function () {
    $t = twoTenants();
    $this->actingAs($t->a->member(OrganizationRole::Finance))
        ->post(route('tax-rules.store'), vatForm($t->a->property->id, ['rate' => '12.5', 'inclusive' => '1', 'effective_to' => '2026-12-31']))
        ->assertSessionHasNoErrors()->assertRedirect(route('tax-rules.index'));

    $this->get(route('tax-rules.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('rates/taxes')->where('canManage', true)->has('taxRules', 1)
        ->where('taxRules.0.name', 'VAT')->where('taxRules.0.rate', 1250)->where('taxRules.0.inclusive', true)
        ->where('taxRules.0.effective_from', '2026-01-01')->where('taxRules.0.effective_to', '2026-12-31'));
    expect(Activity::query()->where('description', 'Tax rule created')->sole()->getExtraProperty('attributes'))
        ->toMatchArray(['name' => 'VAT', 'rate' => 1250, 'inclusive' => true, 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']);
});

test('everyone who sees reservations sees tax rules, but only tax.manage roles change them', function (OrganizationRole $role, bool $manages) {
    $t = twoTenants();
    $this->actingAs($role === OrganizationRole::Owner ? $t->a->owner : $t->a->member($role));

    $response = $this->post(route('tax-rules.store'), vatForm($t->a->property->id));

    $manages ? $response->assertSessionHasNoErrors() : $response->assertForbidden();
    expect(Activity::query()->where('description', 'Tax rule created')->count())->toBe($manages ? 1 : 0);
    $this->get(route('tax-rules.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canManage', $manages));
})->with([
    'owner' => [OrganizationRole::Owner, true],
    'property manager' => [OrganizationRole::PropertyManager, true],
    'finance' => [OrganizationRole::Finance, true],
    'group operations manager' => [OrganizationRole::GroupOperationsManager, false],
    'front desk' => [OrganizationRole::FrontDesk, false],
]);

test('rules sharing a name never overlap in effective dates', function () {
    $t = twoTenants();
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager));
    $add = fn (array $terms) => $this->post(route('tax-rules.store'), vatForm($t->a->property->id, $terms));

    $add(['effective_to' => '2026-06-30'])->assertSessionHasNoErrors();
    $add(['effective_from' => '2026-06-30'])->assertSessionHasErrors('effective_from');
    $add(['effective_from' => '2025-01-01', 'effective_to' => '2026-01-01'])->assertSessionHasErrors('effective_from');
    $add(['effective_from' => '2025-01-01'])->assertSessionHasErrors('effective_from');
    $add(['effective_from' => '2026-07-01'])->assertSessionHasNoErrors();
    $add(['effective_from' => '2030-01-01'])->assertSessionHasErrors('effective_from');
    // Another name, or the same name in another property, may run over the same dates.
    $add(['name' => 'Service charge'])->assertSessionHasNoErrors();
    $this->post(route('tax-rules.store'), vatForm($t->a->otherProperty->id))->assertSessionHasNoErrors();
});

test('a flat per-person-per-night rule needs an amount and an allowed currency, a percentage a rate', function () {
    $t = twoTenants();
    $this->actingAs($t->a->member(OrganizationRole::Finance));
    $flat = fn (array $terms) => $this->post(route('tax-rules.store'), vatForm($t->a->property->id, ['name' => 'Infrastructure tax', 'kind' => 'flat_per_person_night', 'rate' => null, 'amount' => '150', 'currency' => 'USD', ...$terms]));

    $flat(['currency' => 'GBP'])->assertSessionHasErrors('currency');
    $flat(['amount' => '1.5'])->assertSessionHasErrors('amount');
    $flat(['rate' => '18'])->assertSessionHasErrors('rate');
    $flat([])->assertSessionHasNoErrors();
    $this->post(route('tax-rules.store'), vatForm($t->a->property->id, ['rate' => '18.125']))->assertSessionHasErrors('rate');
    $this->post(route('tax-rules.store'), vatForm($t->a->property->id, ['effective_to' => '2025-12-31']))->assertSessionHasErrors('effective_to');

    $this->get(route('tax-rules.index'))->assertInertia(fn (Assert $page) => $page->has('taxRules', 1)
        ->where('taxRules.0.amount', 150)->where('taxRules.0.currency', 'USD')->where('taxRules.0.rate', null));
});

test('a change takes effect from a date: the old version ends the day before, so earlier dates keep their tax', function () {
    $t = twoTenants();
    $this->actingAs($t->a->member(OrganizationRole::Finance));
    $this->post(route('tax-rules.store'), vatForm($t->a->property->id))->assertSessionHasNoErrors();
    $vat = inTenant($t->a->property, fn () => TaxRule::query()->sole());
    $tax = fn (string $date) => inTenant($t->a->property, fn () => TaxCalculator::forProperty($t->a->property, 10000, Currency::USD, $date, 2, ChargeKind::Room)->tax());
    $change = fn (array $terms) => $this->post(route('tax-rules.change', $vat), vatForm($t->a->property->id, ['rate' => '20', 'effective_from' => '2026-07-01', ...$terms]));

    $change(['effective_from' => '2026-01-01'])->assertSessionHasErrors('effective_from');
    $change([])->assertSessionHasNoErrors()->assertRedirect(route('tax-rules.index'));

    expect([$tax('2026-06-30'), $tax('2026-07-01')])->toBe([1800, 2000]);
    $this->get(route('tax-rules.index'))->assertInertia(fn (Assert $page) => $page->has('taxRules', 2)
        ->where('taxRules.0.effective_to', '2026-06-30')->where('taxRules.1.name', 'VAT')->where('taxRules.1.rate', 2000)->where('taxRules.1.effective_from', '2026-07-01'));
    $audit = Activity::query()->where('description', 'Tax rule changed')->sole();
    expect($audit->subject_id)->toBe($vat->id)
        ->and($audit->getExtraProperty('old'))->toEqual(['effective_to' => null])
        ->and($audit->getExtraProperty('attributes'))->toEqual(['effective_to' => '2026-06-30'])
        ->and($audit->getExtraProperty('replacement'))->toMatchArray(['rate' => 2000, 'effective_from' => '2026-07-01']);

    // The ended version can't change again from a date after its end.
    $change(['effective_from' => '2026-08-01'])->assertSessionHasErrors('effective_from');
});

test('a rule ends on a date, is audited, and refuses an end before its start', function () {
    $t = twoTenants();
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager));
    $this->post(route('tax-rules.store'), vatForm($t->a->property->id))->assertSessionHasNoErrors();
    $vat = inTenant($t->a->property, fn () => TaxRule::query()->sole());

    $this->post(route('tax-rules.end', $vat), ['_property' => $t->a->property->id, 'effective_to' => '2025-12-31'])->assertSessionHasErrors('effective_to');
    $this->post(route('tax-rules.end', $vat), ['_property' => $t->a->property->id, 'effective_to' => '2026-03-31'])->assertSessionHasNoErrors();

    $this->get(route('tax-rules.index'))->assertInertia(fn (Assert $page) => $page->where('taxRules.0.effective_to', '2026-03-31'));
    $audit = Activity::query()->where('description', 'Tax rule ended')->sole();
    expect($audit->getExtraProperty('old'))->toEqual(['effective_to' => null])->and($audit->getExtraProperty('attributes'))->toEqual(['effective_to' => '2026-03-31']);

    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->post(route('tax-rules.end', $vat), ['_property' => $t->a->property->id, 'effective_to' => '2026-02-28'])->assertForbidden();
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->post(route('tax-rules.change', $vat), vatForm($t->a->property->id, ['effective_from' => '2026-02-01']))->assertForbidden();
});

test('an ended rule can be extended only up to the next version of the same name', function () {
    $t = twoTenants();
    $this->actingAs($t->a->member(OrganizationRole::Finance));
    $this->post(route('tax-rules.store'), vatForm($t->a->property->id, ['effective_to' => '2026-03-31']))->assertSessionHasNoErrors();
    $this->post(route('tax-rules.store'), vatForm($t->a->property->id, ['effective_from' => '2026-06-01']))->assertSessionHasNoErrors();
    $first = inTenant($t->a->property, fn () => TaxRule::query()->orderBy('effective_from')->first());

    $this->post(route('tax-rules.end', $first), ['_property' => $t->a->property->id, 'effective_to' => '2026-06-01'])->assertSessionHasErrors('effective_to');
    $this->post(route('tax-rules.end', $first), ['_property' => $t->a->property->id, 'effective_to' => '2026-05-31'])->assertSessionHasNoErrors();
});
