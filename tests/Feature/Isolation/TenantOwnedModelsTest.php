<?php

use App\Domains\Tenancy\Models\OrganizationMembership;
use App\Domains\Tenancy\Models\Property;
use App\Domains\Tenancy\Models\PropertyMembership;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Tests\Support\Isolation\TenantOwnedModels;

/*
 * Architecture rule (docs/architecture.md §3.2 rule 3): a model whose table has
 * organization_id is tenant-owned and must use BelongsToTenant; one whose table
 * also has property_id is property-owned and must use BelongsToProperty.
 */
test('every tenant-owned model in the app uses tenant scoping', function () {
    $violations = TenantOwnedModels::violations(TenantOwnedModels::inApp());

    expect($violations)->toBeEmpty(implode("\n", $violations));
});

test('the app\'s models are found', function () {
    expect(TenantOwnedModels::inApp())->toContain(
        Property::class,
        OrganizationMembership::class,
        PropertyMembership::class,
    );
});

test('a tenant-owned model without tenant scoping is reported by name', function () {
    // Probe models over existing tenant tables, so the test needs no schema changes (DDL would end the test transaction on MySQL).
    $unscoped = new class extends Model
    {
        protected $table = 'organization_memberships';
    };
    $organizationScopedOnly = new class extends Model
    {
        use BelongsToTenant;

        protected $table = 'property_memberships';
    };

    $violations = implode("\n", TenantOwnedModels::violations([$unscoped::class, $organizationScopedOnly::class]));

    expect($violations)
        ->toContain($unscoped::class.' (table organization_memberships) has organization_id but does not use BelongsToTenant')
        ->toContain($organizationScopedOnly::class.' (table property_memberships) has property_id but does not use BelongsToProperty')
        ->not->toContain($organizationScopedOnly::class.' (table property_memberships) has organization_id');
});
