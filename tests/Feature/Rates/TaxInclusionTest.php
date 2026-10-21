<?php

use App\Domains\Billing\Actions\PostRoomCharge;
use App\Domains\Rates\Actions\ChangeTaxRule;
use App\Domains\Rates\Actions\CreateTaxRule;
use App\Domains\Rates\Actions\SeedDefaultTaxRules;
use App\Domains\Rates\Models\TaxRule;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;
use Tests\Support\PropertyForm;
use Tests\Support\TwoTenants;

/**
 * Property A of two tenants, Zanzibar, seeded on 2026-12-10 and now on 2026-12-11, so a change can start today.
 * With $stay, a reservation is checked in from 2026-12-10.
 */
function taxInclusionProperty(bool $stay = false): TwoTenants
{
    $t = twoTenants();
    $t->a->property->update(['business_date' => '2026-12-10', 'jurisdiction_profile' => 'zanzibar', 'star_rating' => 4]);
    inTenant($t->a->property, function () use ($t, $stay) {
        app(SeedDefaultTaxRules::class)->handle($t->a->owner, $t->a->property);
        if ($stay) {
            app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner))->update(['status' => ReservationStatus::CheckedIn]);
        }
    });
    $t->a->property->update(['business_date' => '2026-12-11']);

    return $t;
}

/** @param array<string, mixed> $inclusion */
function taxInclusionForm(string $propertyId, array $inclusion): array
{
    return PropertyForm::payload(['_property' => $propertyId, 'jurisdiction_profile' => 'zanzibar', 'star_rating' => 4, 'tax_inclusion' => $inclusion]);
}

/** @return list<array{0: string, 1: bool, 2: string, 3: string|null}> name, inclusive, from, to */
function taxInclusionRules(TwoTenants $t): array
{
    return inTenant($t->a->property, fn () => TaxRule::query()->orderBy('name')->orderBy('effective_from')->get()
        ->map(fn (TaxRule $rule) => [$rule->name, $rule->inclusive, $rule->effective_from->format('Y-m-d'), $rule->effective_to?->format('Y-m-d')])->all());
}

// protects=the edit form reads the seeded defaults from the rules; fails_when=a stored switch disagrees with the calculator.
test('new properties show VAT included and the levy added, read from their rules', function () {
    $t = taxInclusionProperty();

    $this->actingAs($t->a->owner)->get(route('properties.edit', $t->a->property))->assertInertia(fn (Assert $page) => $page
        ->where('taxInclusion.vat.state', 'included')->where('taxInclusion.vat.covers_extras', true)
        ->where('taxInclusion.levy.state', 'added')->where('taxInclusion.levy.covers_extras', false)
        ->where('taxInclusion.vat.earliest', '2026-12-11')->where('canManageTaxes', true)
        ->missing('property.tax_settings.tax_inclusive'));
});

// protects=one save versions both rules from a date, audited; fails_when=old versions are mutated or posted tax changes.
test('the property form versions VAT and levy rules from a date and leaves posted charges alone', function () {
    $t = taxInclusionProperty(stay: true);
    $posting = inTenant($t->a->property, fn () => app(PostRoomCharge::class)->handle($t->a->owner, Reservation::query()->sole(), '2026-12-11'));
    $snapshot = inTenant($t->a->property, fn () => $posting->fresh()->getAttributes());

    $this->actingAs($t->a->owner)->put(route('properties.update', $t->a->property), taxInclusionForm($t->a->property->id, ['vat' => '0', 'levy' => '1', 'effective_from' => '2026-12-12']))
        ->assertSessionHasNoErrors();

    expect(taxInclusionRules($t))->toBe([
        ['Infrastructure levy', false, '2026-12-10', '2026-12-11'], ['Infrastructure levy', true, '2026-12-12', null],
        ['VAT', true, '2026-12-10', '2026-12-11'], ['VAT', false, '2026-12-12', null],
    ])->and(inTenant($t->a->property, fn () => $posting->fresh()->getAttributes()))->toEqual($snapshot);
    $audit = Activity::query()->where('description', 'Tax inclusion changed')->sole();
    expect($audit->getExtraProperty('old'))->toEqual(['vat' => 'included', 'levy' => 'added'])
        ->and($audit->getExtraProperty('attributes'))->toEqual(['vat' => 'added', 'levy' => 'included']);
    $this->get(route('properties.edit', $t->a->property))->assertInertia(fn (Assert $page) => $page
        ->where('taxInclusion.vat.state', 'included')->where('taxInclusion.vat.pending.0.inclusive', false)
        ->where('taxInclusion.vat.pending.0.effective_from', '2026-12-12'));
});

// protects=no change on the day a rule starts and none before the business date; fails_when=a partial write leaves one rule changed.
test('a change before the earliest date is refused and nothing changes', function () {
    $t = taxInclusionProperty();
    $before = taxInclusionRules($t);

    $this->actingAs($t->a->owner)->put(route('properties.update', $t->a->property), taxInclusionForm($t->a->property->id, ['vat' => '0', 'levy' => '1', 'effective_from' => '2026-12-10']))
        ->assertSessionHasErrors('tax_inclusion.effective_from');
    // A rule seeded today can change from tomorrow at the earliest.
    $t->a->property->update(['business_date' => '2026-12-10']);
    $this->put(route('properties.update', $t->a->property), taxInclusionForm($t->a->property->id, ['vat' => '0', 'effective_from' => '2026-12-10']))
        ->assertSessionHasErrors('tax_inclusion.effective_from');

    expect(taxInclusionRules($t))->toBe($before)->and(Activity::query()->where('description', 'Tax inclusion changed')->count())->toBe(0);
});

// protects=one property update is one transaction; fails_when=tax rules change while the rest of the form is refused.
test('tax rules roll back when the rest of the property update is refused', function () {
    $t = taxInclusionProperty(stay: true);
    $before = taxInclusionRules($t);

    // A Zanzibar property with an in-house stay cannot change its profile, so the update is refused after the tax step.
    $this->actingAs($t->a->owner)->put(route('properties.update', $t->a->property), [...taxInclusionForm($t->a->property->id, ['vat' => '0']), 'jurisdiction_profile' => 'mainland_tanzania'])
        ->assertSessionHasErrors();

    expect(taxInclusionRules($t))->toBe($before);
});

// protects=mixed custom rules stay mixed until chosen; fails_when=saving the form silently normalizes them.
test('mixed VAT rules stay mixed until staff choose one, then all change together', function () {
    $t = taxInclusionProperty();
    inTenant($t->a->property, fn () => app(CreateTaxRule::class)->handle($t->a->owner, [
        'zra_tax_category' => 'vat', 'name' => 'VAT on rooms', 'kind' => 'percentage', 'rate' => 300, 'amount' => null, 'currency' => null,
        'inclusive' => false, 'applies_to' => 'room', 'rounding' => 'half_up', 'effective_from' => '2026-12-10', 'effective_to' => null,
    ]));
    $this->actingAs($t->a->owner)->get(route('properties.edit', $t->a->property))->assertInertia(fn (Assert $page) => $page->where('taxInclusion.vat.state', 'mixed'));

    $this->put(route('properties.update', $t->a->property), taxInclusionForm($t->a->property->id, ['vat' => '', 'levy' => '0']))->assertSessionHasNoErrors();
    expect(count(taxInclusionRules($t)))->toBe(3);

    $this->put(route('properties.update', $t->a->property), taxInclusionForm($t->a->property->id, ['vat' => '1', 'effective_from' => '2026-12-12']))->assertSessionHasNoErrors();
    $t->a->property->update(['business_date' => '2026-12-12']);
    $this->get(route('properties.edit', $t->a->property))->assertInertia(fn (Assert $page) => $page->where('taxInclusion.vat.state', 'included'));
});

// protects=editing a rule directly stays the authority and refreshes the controls.
test('changing a rule on the taxes page refreshes the property control', function () {
    $t = taxInclusionProperty();
    $levy = inTenant($t->a->property, fn () => TaxRule::query()->where('name', 'Infrastructure levy')->sole());
    inTenant($t->a->property, fn () => app(ChangeTaxRule::class)->handle($t->a->owner, $levy, [...$levy->terms(), 'inclusive' => true, 'effective_from' => '2026-12-11']));

    $this->actingAs($t->a->owner)->get(route('properties.edit', $t->a->property))->assertInertia(fn (Assert $page) => $page->where('taxInclusion.levy.state', 'included'));
});

// protects=properties.update alone cannot change tax rules; fails_when=a group operations manager rewrites taxes through the property form.
test('only tax.manage members change tax inclusion through the property form', function () {
    $t = taxInclusionProperty();
    $before = taxInclusionRules($t);
    $manager = $t->a->member(OrganizationRole::GroupOperationsManager);

    $this->actingAs($manager)->get(route('properties.edit', $t->a->property))->assertInertia(fn (Assert $page) => $page->where('canManageTaxes', false));
    $this->put(route('properties.update', $t->a->property), taxInclusionForm($t->a->property->id, ['vat' => '0']))->assertForbidden();
    // The same member may still save the rest of the form.
    $this->put(route('properties.update', $t->a->property), PropertyForm::payload(['_property' => $t->a->property->id, 'jurisdiction_profile' => 'zanzibar', 'star_rating' => 4]))->assertSessionHasNoErrors();

    expect(taxInclusionRules($t))->toBe($before);
});

test('a property without matching rules shows no switch, and a new property cannot post one', function () {
    [$owner, , $property] = organizationWithOwner();

    $this->actingAs($owner)->get(route('properties.edit', $property))->assertInertia(fn (Assert $page) => $page
        ->where('taxInclusion.vat.state', 'none')->where('taxInclusion.levy.state', 'none'));
    $this->post(route('properties.store'), PropertyForm::payload(['_property' => $property->id, 'tax_inclusion' => ['vat' => '0']]))
        ->assertSessionHasErrors('tax_inclusion');
});
