<?php

use App\Domains\Platform\Enums\PlatformRole;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

/*
 * Isolation coverage for routes/web/platform.php. See README.md.
 *
 * Platform pages are for platform administrators only. No organization member,
 * owners included, reaches them, so none can see the organization list or hand
 * themselves a platform role. What a platform administrator sees is covered in
 * tests/Feature/Platform.
 */
return [
    'platform.properties.decline-go-live' => RouteCoverage::attempt(function (TwoTenants $t) {
        $property = $t->b->property;
        $property->forceFill(['status' => 'setup', 'go_live_requested_at' => now(), 'go_live_requested_by' => $t->b->owner->id])->save();
        $before = $property->fresh()->getAttributes();
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)
                ->post(route('platform.properties.decline-go-live', $property->id), ['reason' => 'Decline Bravo.'])
                ->assertForbidden();
        }
        expect($property->fresh()->getAttributes())->toEqual($before);

        return $responses;
    }),

    'platform.organizations.show' => RouteCoverage::attempt(fn (TwoTenants $t) => array_values(array_map(
        fn ($member) => $this->actingAs($member)->get(route('platform.organizations.show', $t->b->organization->id))->assertForbidden(),
        $t->a->members(),
    ))),

    'platform.organizations.index' => RouteCoverage::attempt(fn (TwoTenants $t) => array_values(array_map(
        fn ($member) => $this->actingAs($member)->get(route('platform.organizations.index'))->assertForbidden(),
        $t->a->members(),
    ))),

    'platform.staff.index' => RouteCoverage::attempt(fn (TwoTenants $t) => array_values(array_map(
        fn ($member) => $this->actingAs($member)->get(route('platform.staff.index'))->assertForbidden(),
        $t->a->members(),
    ))),

    'platform.staff.store' => RouteCoverage::attempt(function (TwoTenants $t) {
        $owner = $t->a->owner;

        $response = $this->actingAs($owner)
            ->post(route('platform.staff.store'), ['email' => $owner->email, 'role' => PlatformRole::Administrator->value])
            ->assertForbidden();

        expect($owner->hasPlatformRole(PlatformRole::Administrator))->toBeFalse();

        return $response;
    }),

    'platform.staff.destroy' => RouteCoverage::attempt(function (TwoTenants $t) {
        $operator = platformStaff(PlatformRole::ComplianceOperator);

        $response = $this->actingAs($t->a->member(OrganizationRole::Owner))
            ->delete(route('platform.staff.destroy', ['user' => $operator, 'role' => PlatformRole::ComplianceOperator->value]))
            ->assertForbidden();

        expect($operator->hasPlatformRole(PlatformRole::ComplianceOperator))->toBeTrue();

        return $response;
    }),
];
