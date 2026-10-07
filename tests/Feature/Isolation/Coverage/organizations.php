<?php

use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

/*
 * Isolation coverage for routes/web/organizations.php. See README.md.
 */

return [
    // Switching to Bravo, which no Alpha member belongs to, is refused, and the member stays in Alpha.
    'current-organization.update' => RouteCoverage::attempt(fn (TwoTenants $t) => [
        $this->actingAs($t->a->owner)
            ->put(route('current-organization.update'), ['organization_id' => $t->b->organization->id])
            ->assertForbidden(),
        $this->actingAs($t->a->owner)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('tenant.organization.id', $t->a->organization->id)),
    ]),
];
