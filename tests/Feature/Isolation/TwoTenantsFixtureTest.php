<?php

use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;

test('the fixture builds two organizations with two properties each', function () {
    $tenants = twoTenants();

    foreach ([$tenants->a, $tenants->b] as $tenant) {
        expect($tenant->properties)->toHaveCount(2);

        foreach ($tenant->properties as $property) {
            expect($property->organization_id)->toBe($tenant->organization->id);
        }
    }

    expect($tenants->a->organization->id)->not->toBe($tenants->b->organization->id);
});

test('the fixture has a member in every organization role, holding that role in their own organization only', function () {
    $tenants = twoTenants();

    foreach ([[$tenants->a, $tenants->b], [$tenants->b, $tenants->a]] as [$own, $other]) {
        foreach (OrganizationRole::cases() as $role) {
            $member = $own->member($role);

            inTenant($own->organization, fn () => expect($member->fresh()->hasRole($role->value))->toBeTrue());
            inTenant($other->organization, fn () => expect($member->fresh()->roles)->toBeEmpty());

            expect(Tenancy::allows($member, TenantContext::forOrganization($own->organization)))->toBeTrue()
                ->and(Tenancy::allows($member, TenantContext::forOrganization($other->organization)))->toBeFalse();
        }
    }
});

test('organization-level roles may act in both properties; property-level roles only in the first', function () {
    $tenant = twoTenants()->a;

    foreach (OrganizationRole::cases() as $role) {
        $member = $tenant->member($role);
        $organizationWide = in_array($role, [OrganizationRole::Owner, OrganizationRole::GroupOperationsManager], true);

        expect(Tenancy::allows($member, TenantContext::forProperty($tenant->property)))->toBeTrue()
            ->and(Tenancy::allows($member, TenantContext::forProperty($tenant->otherProperty)))->toBe($organizationWide);
    }
});

test('the owner shortcut is the owner member', function () {
    $tenant = twoTenants()->a;

    expect($tenant->owner->is($tenant->member(OrganizationRole::Owner)))->toBeTrue();
});
