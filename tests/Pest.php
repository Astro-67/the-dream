<?php

use App\Domains\Tenancy\Actions\CreateOrganization;
use App\Domains\Tenancy\Data\OrganizationSetup;
use App\Domains\Tenancy\Models\Organization;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A valid organization setup form submission. Override any field by key.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function organizationSetupPayload(array $overrides = []): array
{
    return [
        'organization_name' => 'Spice Island Hotels',
        'country' => 'TZ',
        'property_name' => 'Stone Town Inn',
        'property_type' => 'hotel',
        'address_line' => 'Kenyatta Road 12',
        'city' => 'Zanzibar City',
        'region' => 'Mjini Magharibi',
        'postal_code' => '71101',
        'phone' => '+255 24 223 0000',
        'email' => 'frontdesk@stonetowninn.example',
        'timezone' => 'Africa/Dar_es_Salaam',
        'currency' => 'USD',
        'jurisdiction_profile' => 'zanzibar',
        'zra_hotel_code' => 'ZH-0042',
        ...$overrides,
    ];
}

/**
 * An organization with its first property and owner, built through the same
 * action the setup form uses.
 *
 * @param  array<string, mixed>  $overrides  organization setup fields, as in organizationSetupPayload()
 * @return array{0: User, 1: Organization, 2: Property}
 */
function organizationWithOwner(array $overrides = [], ?User $owner = null): array
{
    $owner ??= User::factory()->create();
    $organization = app(CreateOrganization::class)->handle($owner, OrganizationSetup::fromArray(organizationSetupPayload($overrides)));
    $property = inTenant($organization, fn () => Property::query()->sole());

    return [$owner, $organization, $property];
}

/**
 * Run a callback inside an organization's (or a property's) tenant context.
 *
 * @template TReturn
 *
 * @param  Closure(): TReturn  $callback
 * @return TReturn
 */
function inTenant(Organization|Property $tenant, Closure $callback): mixed
{
    $context = $tenant instanceof Property
        ? TenantContext::forProperty($tenant)
        : TenantContext::forOrganization($tenant);

    return Tenancy::within($context, $callback);
}
