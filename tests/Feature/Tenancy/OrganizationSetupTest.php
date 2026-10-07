<?php

use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

test('a registered user without a membership is sent from operational pages to organization setup', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('organizations.create'));
});

test('a registered user without a membership sees the organization setup form', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('organizations.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('organizations/create'));
});

test('submitting the form creates the organization, its first property and the owner, and lands the owner in the property', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('organizations.store'), organizationSetupPayload())
        ->assertRedirect(route('dashboard'));

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenant.organization.name', 'Spice Island Hotels')
            ->where('tenant.property.name', 'Stone Town Inn')
            ->where('tenant.property.jurisdictionProfile', 'zanzibar')
            ->where('tenant.property.currency', 'USD')
            ->where('tenant.property.timezone', 'Africa/Dar_es_Salaam')
            ->has('tenant.organizations', 1)
            ->has('tenant.properties', 1)
            ->where('auth.permissions', fn ($permissions) => collect($permissions)->contains('properties.manage'))
        );

    $organization = Tenancy::organizationsFor($user)->sole();

    Tenancy::within(TenantContext::forOrganization($organization), function () use ($user) {
        expect($user->hasRole(OrganizationRole::Owner->value))->toBeTrue();
    });
});

test('a repeated submission returns the existing organization instead of creating another', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->post(route('organizations.store'), organizationSetupPayload())->assertRedirect(route('dashboard'));
    $this->post(route('organizations.store'), organizationSetupPayload(['organization_name' => 'Second Try Ltd', 'property_name' => 'Second Inn']))
        ->assertRedirect(route('dashboard'));

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenant.organization.name', 'Spice Island Hotels')
            ->has('tenant.organizations', 1)
            ->has('tenant.properties', 1)
        );
});

test('a member who opens the setup form again is sent to their property', function () {
    [$owner] = organizationWithOwner();

    $this->actingAs($owner)
        ->get(route('organizations.create'))
        ->assertRedirect(route('dashboard'));
});

test('a Zanzibar property carries its Zanzibar-only fields to every page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('organizations.store'), organizationSetupPayload([
        'jurisdiction_profile' => 'zanzibar',
        'zra_hotel_code' => 'ZH-0042',
    ]));

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('tenant.property.zraHotelCode', 'ZH-0042'));
});

test('a mainland property is never shown Zanzibar-only fields, even if they were submitted', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('organizations.store'), organizationSetupPayload([
        'jurisdiction_profile' => 'mainland_tanzania',
        'zra_hotel_code' => 'ZH-0042',
    ]))->assertRedirect(route('dashboard'));

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenant.property.jurisdictionProfile', 'mainland_tanzania')
            ->missing('tenant.property.zraHotelCode')
        );
});

test('a mainland property can be set up without any Zanzibar-only field', function () {
    $user = User::factory()->create();

    $payload = organizationSetupPayload(['jurisdiction_profile' => 'mainland_tanzania', 'currency' => 'TZS']);
    unset($payload['zra_hotel_code']);

    $this->actingAs($user)
        ->post(route('organizations.store'), $payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard'));
});

test('the setup form rejects missing and unsupported values', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('organizations.store'), organizationSetupPayload([
            'organization_name' => '',
            'currency' => 'GBP',
            'jurisdiction_profile' => 'kenya',
            'timezone' => 'Mars/Olympus',
        ]))
        ->assertSessionHasErrors(['organization_name', 'currency', 'jurisdiction_profile', 'timezone']);

    $this->get(route('dashboard'))->assertRedirect(route('organizations.create'));
});

test('the audit trail records the organization and property creation with the actor, organization and property', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('organizations.store'), organizationSetupPayload());

    $organization = Tenancy::organizationsFor($user)->sole();
    $property = inTenant($organization, fn () => Property::query()->sole());

    $organizationEntry = Activity::query()->where('event', 'created')->where('subject_type', $organization->getMorphClass())->sole();
    expect($organizationEntry->causer?->is($user))->toBeTrue()
        ->and($organizationEntry->subject_id)->toBe($organization->id)
        ->and($organizationEntry->getExtraProperty('organization_id'))->toBe($organization->id)
        ->and($organizationEntry->getExtraProperty('property_id'))->toBe($property->id);

    $propertyEntry = Activity::query()->where('event', 'created')->where('subject_type', $property->getMorphClass())->sole();
    expect($propertyEntry->causer?->is($user))->toBeTrue()
        ->and($propertyEntry->subject_id)->toBe($property->id)
        ->and($propertyEntry->getExtraProperty('organization_id'))->toBe($organization->id)
        ->and($propertyEntry->getExtraProperty('property_id'))->toBe($property->id)
        ->and($propertyEntry->getExtraProperty('attributes')['jurisdiction_profile'])->toBe('zanzibar');
});
