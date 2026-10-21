<?php

use App\Domains\Tenancy\Actions\ArchiveProperty;
use App\Domains\Tenancy\Actions\CreateDemoProperty;
use App\Domains\Tenancy\Actions\SendInvitation;
use App\Domains\Tenancy\Data\InvitationDetails;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\Invitation;
use App\Domains\Tenancy\Models\OrganizationMembership;
use App\Domains\Tenancy\Models\Property;
use App\Domains\Tenancy\Notifications\InvitationSent;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\PropertyForm;
use Tests\Support\TwoTenants;

/** @return array<string, array<string, mixed>> */
$bravoProperties = fn (TwoTenants $t): array => inTenant($t->b->organization, fn () => Property::query()
    ->orderBy('id')->get()->keyBy('id')->map(fn (Property $p) => [...$p->only(['name', 'email', 'currency', 'timezone', 'star_rating', 'tin', 'z_number', 'vrn', 'front_desk_settings', 'tax_settings', 'payment_settings']), 'archived_at' => $p->archived_at?->toIso8601String()])->all());

$bravoMembership = fn (TwoTenants $t): OrganizationMembership => inTenant($t->b->organization, fn () => OrganizationMembership::query()
    ->where('user_id', $t->b->member(OrganizationRole::FrontDesk)->id)->sole());
$bravoAccess = fn (TwoTenants $t): array => inTenant($t->b->organization, fn () => OrganizationMembership::query()
    ->orderBy('id')->get()->keyBy('user_id')->map(fn (OrganizationMembership $m) => $m->grant()->toAuditValues())->all());
$bravoInvitation = function (TwoTenants $t): array {
    Notification::fake();
    $invitation = inTenant($t->b->organization, fn () => app(SendInvitation::class)->handle($t->b->owner, InvitationDetails::fromArray([
        'email' => 'newcomer@bravo.test', 'role' => 'front_desk', 'property_ids' => [$t->b->property->id],
    ])));
    $link = '';
    Notification::assertSentOnDemand(InvitationSent::class, function (InvitationSent $notification) use (&$link) {
        $link = $notification->url();

        return true;
    });

    return [$invitation, $link];
};

return [
    'organizations.create' => RouteCoverage::visit(),
    'organizations.store' => RouteCoverage::attempt(fn (TwoTenants $t) => [
        $this->actingAs($t->a->owner)->post(route('organizations.store'), organizationSetupPayload(['organization_name' => $t->b->organization->name, 'property_name' => $t->b->property->name]))->assertRedirect(route('dashboard')),
        $this->actingAs($t->a->owner)->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('tenant.organization.id', $t->a->organization->id)->has('tenant.organizations', 1)),
    ]),
    'current-organization.update' => RouteCoverage::attempt(fn (TwoTenants $t) => [
        $this->actingAs($t->a->owner)->put(route('current-organization.update'), ['organization_id' => $t->b->organization->id])->assertForbidden(),
        $this->actingAs($t->a->owner)->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('tenant.organization.id', $t->a->organization->id)),
    ]),
    'current-property.update' => RouteCoverage::attempt(fn (TwoTenants $t) => [
        $this->actingAs($t->a->owner)->put(route('current-property.update'), ['property_id' => $t->b->property->id])->assertForbidden(),
        $this->actingAs($t->a->owner)->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('tenant.organization.id', $t->a->organization->id)),
    ]),
    'properties.index' => RouteCoverage::visit(),
    'properties.create' => RouteCoverage::visit(),
    'properties.store' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoProperties) {
        $before = $bravoProperties($t);
        $responses = [
            $this->actingAs($t->a->owner)->post(route('properties.store'), PropertyForm::payload(['_property' => $t->b->property->id]))->assertForbidden(),
            $this->actingAs($t->a->owner)->post(route('properties.store'), PropertyForm::payload(['_property' => $t->a->property->id]))->assertRedirect(route('properties.index')),
        ];
        expect($bravoProperties($t))->toBe($before);

        return $responses;
    }),
    'properties.demo.store' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoProperties) {
        $before = $bravoProperties($t);
        $responses = [
            $this->actingAs($t->a->owner)->post(route('properties.demo.store'), ['_property' => $t->b->property->id])->assertForbidden(),
            $this->actingAs($t->a->owner)->post(route('properties.demo.store'), ['_property' => $t->a->property->id])->assertRedirect(route('properties.index')),
            $this->actingAs($t->a->owner)->get(route('properties.index')),
        ];
        expect($bravoProperties($t))->toBe($before);

        return $responses;
    }),
    'properties.destroy' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoProperties) {
        $demo = inTenant($t->b->property, fn () => app(CreateDemoProperty::class)->handle($t->b->owner));
        $before = $bravoProperties($t);
        $responses = [
            $this->actingAs($t->a->owner)->delete(route('properties.destroy', $demo->id), ['_property' => $t->a->property->id])->assertNotFound(),
            $this->actingAs($t->a->owner)->delete(route('properties.destroy', $demo->id), ['_property' => $demo->id])->assertForbidden(),
        ];
        expect($bravoProperties($t))->toBe($before)->toHaveKey($demo->id);

        return $responses;
    }),
    'properties.edit' => RouteCoverage::attempt(fn (TwoTenants $t) => $this->actingAs($t->a->owner)->get(route('properties.edit', $t->b->property->id))->assertNotFound()),
    'properties.update' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoProperties) {
        $before = $bravoProperties($t);
        $payload = PropertyForm::payload(['name' => 'Hijacked by Alpha']);
        $responses = [
            $this->actingAs($t->a->owner)->put(route('properties.update', $t->b->property->id), [...$payload, '_property' => $t->a->property->id])->assertNotFound(),
            $this->actingAs($t->a->owner)->put(route('properties.update', $t->b->property->id), [...$payload, '_property' => $t->b->property->id])->assertForbidden(),
        ];
        expect($bravoProperties($t))->toBe($before);

        return $responses;
    }),
    'properties.archive' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoProperties) {
        $before = $bravoProperties($t);
        $responses = [
            $this->actingAs($t->a->owner)->post(route('properties.archive', $t->b->otherProperty->id), ['_property' => $t->a->property->id])->assertNotFound(),
            $this->actingAs($t->a->owner)->post(route('properties.archive', $t->b->otherProperty->id), ['_property' => $t->b->property->id])->assertForbidden(),
        ];
        expect($bravoProperties($t))->toBe($before);

        return $responses;
    }),
    'properties.restore' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoProperties) {
        inTenant($t->b->property, fn () => app(ArchiveProperty::class)->handle($t->b->owner, $t->b->otherProperty));
        $before = $bravoProperties($t);
        $responses = [
            $this->actingAs($t->a->owner)->delete(route('properties.restore', $t->b->otherProperty->id), ['_property' => $t->a->property->id])->assertNotFound(),
            $this->actingAs($t->a->owner)->delete(route('properties.restore', $t->b->otherProperty->id), ['_property' => $t->b->property->id])->assertForbidden(),
        ];
        expect($bravoProperties($t))->toBe($before)->and($before[$t->b->otherProperty->id]['archived_at'])->not->toBeNull();

        return $responses;
    }),
    'properties.go-live' => RouteCoverage::attempt(function (TwoTenants $t) {
        $t->b->otherProperty->forceFill(['status' => 'setup'])->save();
        $responses = [
            $this->actingAs($t->a->owner)->post(route('properties.go-live', $t->b->otherProperty->id), ['_property' => $t->a->property->id])->assertNotFound(),
            $this->actingAs($t->a->owner)->post(route('properties.go-live', $t->b->otherProperty->id), ['_property' => $t->b->property->id])->assertForbidden(),
        ];
        expect($t->b->otherProperty->fresh()?->isLive())->toBeFalse();

        return $responses;
    }),
    'properties.taxes-reviewed' => RouteCoverage::attempt(function (TwoTenants $t) {
        $responses = [
            $this->actingAs($t->a->owner)->post(route('properties.taxes-reviewed', $t->b->property->id), ['_property' => $t->a->property->id])->assertNotFound(),
            $this->actingAs($t->a->owner)->post(route('properties.taxes-reviewed', $t->b->property->id), ['_property' => $t->b->property->id])->assertForbidden(),
        ];
        expect($t->b->property->fresh()?->taxes_reviewed_at)->toBeNull();

        return $responses;
    }),
    'members.index' => RouteCoverage::visit(),
    'members.edit' => RouteCoverage::attempt(fn (TwoTenants $t) => $this->actingAs($t->a->owner)->get(route('members.edit', $bravoMembership($t)->id))->assertNotFound()),
    'members.update' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoMembership, $bravoAccess) {
        $m = $bravoMembership($t);
        $before = $bravoAccess($t);
        $responses = [
            $this->actingAs($t->a->owner)->put(route('members.update', $m), ['role' => 'auditor', 'all_properties' => false, 'property_ids' => [$t->a->property->id], '_property' => $t->a->property->id])->assertNotFound(),
            $this->actingAs($t->a->owner)->put(route('members.update', $m), ['role' => 'auditor', 'all_properties' => false, 'property_ids' => [$t->a->property->id], '_property' => $t->b->property->id])->assertForbidden(),
        ];
        expect($bravoAccess($t))->toBe($before);

        return $responses;
    }),
    'members.destroy' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoMembership, $bravoAccess) {
        $m = $bravoMembership($t);
        $before = $bravoAccess($t);
        $responses = [
            $this->actingAs($t->a->owner)->delete(route('members.destroy', $m), ['_property' => $t->a->property->id])->assertNotFound(),
            $this->actingAs($t->a->owner)->delete(route('members.destroy', $m), ['_property' => $t->b->property->id])->assertForbidden(),
        ];
        expect($bravoAccess($t))->toBe($before);

        return $responses;
    }),
    'members.transfer-ownership' => RouteCoverage::attempt(fn (TwoTenants $t) => [
        $this->actingAs($t->a->owner)->post(route('members.transfer-ownership', $bravoMembership($t)), ['role' => 'owner', 'all_properties' => true, '_property' => $t->a->property->id])->assertNotFound(),
        $this->actingAs($t->a->owner)->post(route('members.transfer-ownership', $bravoMembership($t)), ['role' => 'owner', 'all_properties' => true, '_property' => $t->b->property->id])->assertForbidden(),
    ]),
    'invitations.index' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoInvitation) {
        $bravoInvitation($t);

        return [$this->actingAs($t->a->owner)->get(route('invitations.index'))->assertOk(), $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->get(route('invitations.index'))->assertOk()];
    }),
    'invitations.create' => RouteCoverage::visit(),
    'invitations.store' => RouteCoverage::attempt(function (TwoTenants $t) {
        $invite = ['email' => 'spy@alpha.test', 'role' => 'front_desk', 'property_ids' => [$t->b->property->id]];
        $responses = [
            $this->actingAs($t->a->owner)->post(route('invitations.store'), [...$invite, '_property' => $t->a->property->id])->assertSessionHasErrors('property_ids'),
            $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->post(route('invitations.store'), [...$invite, '_property' => $t->a->property->id])->assertSessionHasErrors('property_ids'),
            $this->actingAs($t->a->owner)->post(route('invitations.store'), [...$invite, '_property' => $t->b->property->id])->assertForbidden(),
        ];
        expect(inTenant($t->b->organization, fn () => Invitation::query()->count()))->toBe(0);

        return $responses;
    }),
    'invitations.destroy' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoInvitation) {
        [$invitation] = $bravoInvitation($t);
        $response = $this->actingAs($t->a->owner)->delete(route('invitations.destroy', $invitation), ['_property' => $t->a->property->id])->assertNotFound();
        expect(inTenant($t->b->organization, fn () => Invitation::query()->pending()->count()))->toBe(1);

        return $response;
    }),
    'invitations.show' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoInvitation) {
        [, $link] = $bravoInvitation($t);
        $token = basename($link);

        return [
            $this->actingAs($t->a->owner)->get(route('invitations.show', ['organization' => $t->b->organization->id, 'token' => str_repeat('a', 48)]))->assertNotFound(),
            $this->actingAs($t->a->owner)->get(route('invitations.show', ['organization' => $t->a->organization->id, 'token' => $token]))->assertNotFound(),
        ];
    }),
    'invitations.accept' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoInvitation) {
        [, $link] = $bravoInvitation($t);
        $responses = [
            $this->actingAs($t->a->owner)->post(route('invitations.accept', ['organization' => $t->b->organization->id, 'token' => str_repeat('a', 48)]))->assertNotFound(),
            $this->actingAs($t->a->owner)->post($link)->assertForbidden(),
        ];
        expect(inTenant($t->b->organization, fn () => Invitation::query()->pending()->count()))->toBe(1);
        $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->has('tenant.organizations', 1));

        return $responses;
    }),
];
