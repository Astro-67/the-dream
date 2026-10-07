<?php

use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\OrganizationMembership;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

/*
 * Isolation coverage for routes/web/members.php. See README.md.
 */

/**
 * Bravo's front-desk membership, the target of every attack below.
 */
$bravoMembership = fn (TwoTenants $t): OrganizationMembership => inTenant($t->b->organization, fn () => OrganizationMembership::query()
    ->where('user_id', $t->b->member(OrganizationRole::FrontDesk)->id)->sole());

/**
 * Every Bravo member's role and properties as stored, to prove an attack changed nothing.
 *
 * @return array<string, array<string, mixed>>
 */
$bravoAccess = fn (TwoTenants $t): array => inTenant($t->b->organization, fn () => OrganizationMembership::query()
    ->orderBy('id')->get()->keyBy('user_id')->map(fn (OrganizationMembership $m) => $m->grant()->toAuditValues())->all());

/**
 * Sends the same write against Bravo's member twice: through Alpha (not found), and
 * from a form carrying Bravo's property (refused). Bravo's members stay as they were.
 *
 * @param  array<string, mixed>  $payload
 */
$attackBravoMember = fn (string $method, string $routeName, array $payload) => function (TwoTenants $t) use ($method, $routeName, $payload, $bravoMembership, $bravoAccess) {
    $membership = $bravoMembership($t);
    $before = $bravoAccess($t);

    $responses = [
        $this->actingAs($t->a->owner)
            ->{$method}(route($routeName, $membership->id), [...$payload, '_property' => $t->a->property->id])
            ->assertNotFound(),
        $this->actingAs($t->a->owner)
            ->{$method}(route($routeName, $membership->id), [...$payload, '_property' => $t->b->property->id])
            ->assertForbidden(),
    ];

    expect($bravoAccess($t))->toBe($before);

    return $responses;
};

$grant = ['role' => 'auditor', 'all_properties' => false, 'property_ids' => []];

return [
    // Only Alpha's members are listed, to owners and to members.view roles alike.
    'members.index' => RouteCoverage::visit(),

    'members.edit' => RouteCoverage::attempt(fn (TwoTenants $t) => $this->actingAs($t->a->owner)
        ->get(route('members.edit', $bravoMembership($t)->id))
        ->assertNotFound()),

    'members.update' => RouteCoverage::attempt(fn (TwoTenants $t) => $attackBravoMember('put', 'members.update', [
        ...$grant, 'property_ids' => [$t->a->property->id],
    ])->call($this, $t)),

    'members.destroy' => RouteCoverage::attempt(fn (TwoTenants $t) => $attackBravoMember('delete', 'members.destroy', [])->call($this, $t)),

    // Taking ownership of Bravo, or handing Alpha's to a Bravo member, is not possible.
    'members.transfer-ownership' => RouteCoverage::attempt(fn (TwoTenants $t) => $attackBravoMember('post', 'members.transfer-ownership', [
        'role' => 'owner', 'all_properties' => true,
    ])->call($this, $t)),
];
