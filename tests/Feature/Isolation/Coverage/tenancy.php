<?php

use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

/*
 * Isolation coverage for routes/web/tenancy.php. See README.md.
 */
return [
    'organizations.create' => RouteCoverage::visit(),

    // A member re-submitting organization setup gets their own organization back, never a way into Bravo's.
    'organizations.store' => RouteCoverage::attempt(fn (TwoTenants $t) => [
        $this->actingAs($t->a->owner)
            ->post(route('organizations.store'), organizationSetupPayload([
                'organization_name' => $t->b->organization->name,
                'property_name' => $t->b->property->name,
            ]))
            ->assertRedirect(route('dashboard')),
        $this->actingAs($t->a->owner)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('tenant.organization.id', $t->a->organization->id)
                ->has('tenant.organizations', 1)),
    ]),
];
