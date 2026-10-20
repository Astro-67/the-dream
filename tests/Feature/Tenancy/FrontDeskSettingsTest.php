<?php

use App\Domains\Rates\Models\TaxRule;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PropertyForm;

test('managers edit settings and front desk reads them without changing another property', function () {
    $t = twoTenants();
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))
        ->put(route('properties.update', $t->a->property), PropertyForm::payload([
            '_property' => $t->a->property->id, 'jurisdiction_profile' => 'zanzibar',
            'star_rating' => 4, 'tin' => 'TIN-123', 'z_number' => 'Z-123', 'vrn' => 'VRN-123',
            'front_desk_settings' => ['book_ahead_days' => '180', 'automatic_checkout_enabled' => '1', 'automatic_checkout_summary_email' => 'desk@example.test'],
            'tax_settings' => ['zra_tax_category' => 'hotel_levy', 'service_charge_enabled' => true],
            'payment_settings' => ['accepted_methods' => ['cash', 'card'], 'deposit_percentage' => 25],
        ]))->assertSessionHasNoErrors()->assertRedirect();
    $this->get(route('properties.edit', $t->a->property))->assertInertia(fn (Assert $page) => $page
        ->where('property.star_rating', 4)->where('property.tin', 'TIN-123')->where('property.z_number', 'Z-123')->where('property.vrn', 'VRN-123')
        ->where('property.front_desk_settings.book_ahead_days', 180)->where('property.front_desk_settings.minimum_stay', 1)
        ->where('property.tax_settings.zra_tax_category', 'hotel_levy')->where('property.payment_settings.accepted_methods', ['cash', 'card']));
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('tenant.property.frontDeskSettings.book_ahead_days', 180)->where('tenant.property.paymentSettings.deposit_percentage', 25));
    expect($t->b->property->fresh()->settings()['front_desk_settings']['book_ahead_days'])->toBe(365);
    $this->put(route('properties.update', $t->a->property), PropertyForm::payload([
        '_property' => $t->a->property->id, 'front_desk_settings' => ['book_ahead_days' => 1],
    ]))->assertForbidden();
});

test('property settings reject invalid values', function (string $field, mixed $value) {
    [$owner, , $property] = organizationWithOwner();
    $payload = PropertyForm::payload(['_property' => $property->id]);
    data_set($payload, $field, $value);
    $this->actingAs($owner)->put(route('properties.update', $property), $payload)->assertSessionHasErrors($field);
})->with([
    ['star_rating', 0], ['star_rating', 6], ['tin', str_repeat('x', 65)], ['z_number', []], ['vrn', str_repeat('x', 65)],
    ['front_desk_settings.book_ahead_days', -1], ['front_desk_settings.minimum_notice_hours', 1.5],
    ['front_desk_settings.same_day_cutoff_time', '25:00'], ['front_desk_settings.auto_cancel_unpaid_hours', -1],
    ['front_desk_settings.free_cancellation_hours', -1], ['front_desk_settings.default_check_in_time', 'noon'],
    ['front_desk_settings.default_check_out_time', '24:00'], ['front_desk_settings.walk_ins_enabled', 'yes'],
    ['front_desk_settings.walk_in_requires_id', 'yes'], ['front_desk_settings.maximum_overbooking_percentage', 101],
    ['front_desk_settings.minimum_stay', 0], ['front_desk_settings.maximum_stay', 0], ['front_desk_settings.passport_required_for_foreign_guests', 'yes'],
    ['front_desk_settings.automatic_checkout_enabled', 'yes'], ['front_desk_settings.automatic_checkout_time', 'bad'],
    ['front_desk_settings.automatic_checkout_include_unpaid', 'yes'], ['front_desk_settings.automatic_checkout_include_overstays', 'yes'],
    ['front_desk_settings.automatic_checkout_summary_email', 'bad'], ['tax_settings.zra_tax_category', 'invalid'],
    ['tax_settings.tax_inclusive', 'yes'], ['tax_settings.tax_label', ''], ['tax_settings.tax_rate', 101],
    ['tax_settings.service_charge_enabled', 'yes'], ['tax_settings.service_charge_rate', -1], ['tax_settings.show_breakdown_on_bill', 'yes'],
    ['tax_settings.show_breakdown_on_receipt', 'yes'], ['payment_settings.accepted_methods.0', 'bitcoin'],
    ['payment_settings.block_checkout_if_unpaid', 'yes'], ['payment_settings.manager_override', 'yes'],
    ['payment_settings.deposit_percentage', 101], ['payment_settings.payment_due_days', -1],
]);

test('maximum stay cannot be below the effective minimum on a partial update', function () {
    [$owner, , $property] = organizationWithOwner();
    $this->actingAs($owner)->put(route('properties.update', $property), PropertyForm::payload([
        '_property' => $property->id, 'front_desk_settings' => ['minimum_stay' => 100],
    ]))->assertSessionHasErrors('front_desk_settings.maximum_stay');
});

test('new Zanzibar properties receive inclusive VAT and the USD infrastructure levy for their star rating', function (?int $stars, int $levy) {
    [$owner, , $first] = organizationWithOwner();
    $this->actingAs($owner)->post(route('properties.store'), PropertyForm::payload([
        '_property' => $first->id, 'jurisdiction_profile' => 'zanzibar', 'star_rating' => $stars,
    ]))->assertSessionHasNoErrors();
    $property = inTenant($first, fn () => Property::query()->where('name', 'Serengeti Gate Lodge')->sole());
    $rules = inTenant($property, fn () => TaxRule::query()->orderBy('name')->get());
    expect($rules)->toHaveCount(2)
        ->and($rules[0]->name)->toBe('Infrastructure levy')->and($rules[0]->amount)->toBe($levy)
        ->and($rules[0]->currency->value)->toBe('USD')->and($rules[0]->zra_tax_category->value)->toBe('hotel_levy')
        ->and($rules[1]->rate)->toBe(1500)->and($rules[1]->inclusive)->toBeTrue()->and($rules[1]->zra_tax_category->value)->toBe('vat');
})->with([[null, 200], [1, 200], [2, 200], [3, 400], [4, 500], [5, 500]]);

test('settings updates preserve omitted values and normalize boolean form values', function () {
    [$owner, , $property] = organizationWithOwner();
    $this->actingAs($owner)->put(route('properties.update', $property), PropertyForm::payload([
        '_property' => $property->id, 'front_desk_settings' => ['walk_ins_enabled' => '0', 'book_ahead_days' => '180'],
    ]))->assertSessionHasNoErrors();
    $this->put(route('properties.update', $property), PropertyForm::payload([
        '_property' => $property->id, 'front_desk_settings' => ['minimum_notice_hours' => '12'],
    ]))->assertSessionHasNoErrors();
    $settings = $property->fresh()->settings()['front_desk_settings'];
    expect($settings['walk_ins_enabled'])->toBeFalse()->and($settings['book_ahead_days'])->toBe(180)->and($settings['minimum_notice_hours'])->toBe(12);
});

test('organization setup stores optional tax registration and seeds taxes for the selected stars', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('organizations.store'), organizationSetupPayload([
        'star_rating' => '3', 'tin' => 'TIN-321', 'z_number' => 'Z-321', 'vrn' => 'VRN-321',
    ]))->assertSessionHasNoErrors();
    $context = Tenancy::defaultContextFor($user);
    Tenancy::within($context, function () {
        $property = Property::query()->sole();
        expect($property->star_rating)->toBe(3)->and($property->tin)->toBe('TIN-321')->and($property->z_number)->toBe('Z-321')->and($property->vrn)->toBe('VRN-321');
        expect(TaxRule::query()->where('name', 'Infrastructure levy')->sole()->amount)->toBe(400);
    });
});
