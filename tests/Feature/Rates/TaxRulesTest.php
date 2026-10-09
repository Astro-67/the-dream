<?php

use App\Domains\Tenancy\Enums\OrganizationRole;
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
