<?php

use App\Domains\Platform\Actions\AssignPlatformRole;
use App\Domains\Platform\Enums\PlatformRole;
use App\Domains\Rates\Models\TaxRule;
use App\Domains\Tenancy\Actions\CreateOrganization;
use App\Domains\Tenancy\Data\OrganizationSetup;
use App\Domains\Tenancy\Enums\PropertyStatus;
use App\Domains\Tenancy\Models\Organization;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\TwoTenants;
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
 * action the setup form uses. The owner has two-factor authentication on, as
 * owner-only actions require (#13), unless one is passed in.
 *
 * @param  array<string, mixed>  $overrides  organization setup fields, as in organizationSetupPayload()
 * @return array{0: User, 1: Organization, 2: Property}
 */
function organizationWithOwner(array $overrides = [], ?User $owner = null): array
{
    $owner ??= User::factory()->withTwoFactor()->create();
    $organization = app(CreateOrganization::class)->handle($owner, OrganizationSetup::fromArray(organizationSetupPayload([
        'star_rating' => 3,
        'tin' => 'TIN-0042',
        'z_number' => 'Z-0042',
        'vrn' => 'VRN-0042',
        ...$overrides,
    ])));
    $property = inTenant($organization, fn () => Property::query()->sole());
    // Operational fixtures keep explicit tax arrangements; default seeding is tested through HTTP setup/creation.
    inTenant($property, function (): void {
        $rules = TaxRule::query()->pluck('id');
        Activity::query()->where('subject_type', TaxRule::class)->whereIn('subject_id', $rules)->delete();
        TaxRule::query()->delete();
    });
    // An operating hotel: tests of setup and go-live start from setupProperty().
    $property->forceFill(['status' => PropertyStatus::Live])->save();

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

/**
 * A member of platform staff, granted the role the way a seed or console command does.
 * With two-factor authentication enabled unless $twoFactor is false.
 *
 * @param  array<string, mixed>  $attributes
 */
function platformStaff(PlatformRole $role = PlatformRole::Administrator, bool $twoFactor = true, array $attributes = []): User
{
    $factory = User::factory();
    $user = ($twoFactor ? $factory->withTwoFactor() : $factory)->create($attributes);

    app(AssignPlatformRole::class)->handle($user, $role);

    return $user;
}

/**
 * The isolation fixture: organizations Alpha ($tenants->a) and Bravo ($tenants->b),
 * each with two properties and a member in every organization role. See
 * Tests\Support\TenantFixture for what each side holds.
 */
function twoTenants(): TwoTenants
{
    return TwoTenants::build();
}
