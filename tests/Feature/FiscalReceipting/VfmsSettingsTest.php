<?php

use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TenantFixture;

/** @return array<string, string> */
function vfmsCredentials(TenantFixture $tenant, array $overrides = []): array
{
    return [
        '_property' => $tenant->property->id,
        'integration_id' => 'INT-1001',
        'registration_id' => 'REG-2002',
        'token' => 'tok-secret',
        ...$overrides,
    ];
}

// protects=only Owner and Property manager hold VFMS credentials (spec #156); fails_when=another role saves them or the managers are refused; no_claim=what the page shows.
test('only the owner and property manager save VFMS credentials', function (OrganizationRole $role, bool $manages) {
    $t = twoTenants();
    $member = $role === OrganizationRole::Owner ? $t->a->owner : $t->a->member($role);

    $response = $this->actingAs($member)->put(route('properties.vfms.update', $t->a->property), vfmsCredentials($t->a));

    $manages ? $response->assertSessionHasNoErrors()->assertRedirect() : $response->assertForbidden();
    $this->actingAs($t->a->owner)->get(route('properties.edit', $t->a->property))
        ->assertInertia(fn (Assert $page) => $page->where('vfms.integration_id', $manages ? 'INT-1001' : null));
})->with([
    'owner' => [OrganizationRole::Owner, true],
    'property manager' => [OrganizationRole::PropertyManager, true],
    'group operations manager' => [OrganizationRole::GroupOperationsManager, false],
    'front desk' => [OrganizationRole::FrontDesk, false],
    'finance' => [OrganizationRole::Finance, false],
    'housekeeping' => [OrganizationRole::Housekeeping, false],
    'auditor' => [OrganizationRole::Auditor, false],
]);
