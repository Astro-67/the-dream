<?php

use App\Domains\Subscriptions\Models\Subscription;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

test('a platform administrator opens an organization with its owners and subscription', function () {
    $tenants = twoTenants();
    inTenant($tenants->a->organization, fn () => Subscription::current()->update([
        'status' => 'active', 'current_period_ends_at' => '2026-12-01 00:00:00',
    ]));

    $admin = platformStaff();
    $this->actingAs($admin)
        ->get(route('platform.organizations.show', $tenants->a->organization->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('platform/organizations/show')
            ->where('organization.id', $tenants->a->organization->id)
            ->where('organization.name', 'Alpha Hotels Group')
            ->where('organization.owners', [['name' => 'Alpha Owner', 'email' => 'owner@alpha.test']])
            ->where('subscription.plan', 'Essential')
            ->where('subscription.status', 'active')
            ->where('subscription.currentPeriodEndsAt', '2026-12-01T00:00:00+00:00')
            ->where('tenant.organization', null)
            ->where('tenant.property', null));

    $entry = Activity::query()->where('log_name', 'platform')->where('event', 'platform_context_entered')->sole();
    expect($entry->causer?->is($admin))->toBeTrue()
        ->and($entry->getExtraProperty('reason'))->toBe('Platform administrator viewed organization '.$tenants->a->organization->id);
});
