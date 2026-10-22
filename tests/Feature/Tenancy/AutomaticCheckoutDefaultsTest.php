<?php

use App\Domains\Tenancy\Enums\PropertyStatus;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\PropertyForm;

test('unpaid checkout backfill changes only missing preferences and keeps explicit choices', function (?array $settings, array $expected) {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['front_desk_settings' => $settings]);
    $migration = require database_path('migrations/2026_10_31_000106_front_desk_default_skip_unpaid_checkout.php');
    $migration->up();
    $migration->up();
    $migration->down();

    expect($property->fresh()->front_desk_settings)->toEqual($expected)
        ->and($property->fresh()->status)->toBe(PropertyStatus::Live);
    $this->actingAs($owner)->get(route('properties.edit', $property))->assertInertia(fn (Assert $page) => $page
        ->where('property.front_desk_settings.automatic_checkout_include_unpaid', $expected['automatic_checkout_include_unpaid']));
})->with([
    [null, ['automatic_checkout_include_unpaid' => false]],
    [[], ['automatic_checkout_include_unpaid' => false]],
    [['automatic_checkout_enabled' => true], ['automatic_checkout_enabled' => true, 'automatic_checkout_include_unpaid' => false]],
    [['automatic_checkout_enabled' => false, 'automatic_checkout_include_unpaid' => true], ['automatic_checkout_enabled' => false, 'automatic_checkout_include_unpaid' => true]],
    [['automatic_checkout_include_unpaid' => false], ['automatic_checkout_include_unpaid' => false]],
]);

test('explicit unpaid automation choices are saved and audited without enabling checkout', function () {
    [$owner, , $property] = organizationWithOwner();
    $this->actingAs($owner)->put(route('properties.update', $property), PropertyForm::payload([
        '_property' => $property->id, 'front_desk_settings' => ['automatic_checkout_include_unpaid' => true],
    ]))->assertSessionHasNoErrors();

    $this->get(route('properties.edit', $property))->assertInertia(fn (Assert $page) => $page
        ->where('property.front_desk_settings.automatic_checkout_enabled', false)
        ->where('property.front_desk_settings.automatic_checkout_include_unpaid', true));
    $audit = Activity::query()->where('event', 'updated')->where('subject_id', $property->id)->sole();
    expect($audit->properties['old']['front_desk_settings']['automatic_checkout_include_unpaid'])->toBeFalse()
        ->and($audit->properties['attributes']['front_desk_settings']['automatic_checkout_include_unpaid'])->toBeTrue();
});
