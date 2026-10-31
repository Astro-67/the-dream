<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Actions\DeactivateRoom;
use App\Domains\Platform\Enums\PlatformRole;
use App\Domains\Subscriptions\Actions\TransitionSubscription;
use App\Domains\Subscriptions\Enums\BillingPeriod;
use App\Domains\Subscriptions\Enums\SubscriptionStatus;
use App\Domains\Subscriptions\Models\Plan;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionStateChange;
use App\Domains\Tenancy\Actions\ArchiveProperty;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\InventoryForm;

/** @param array<string, mixed> $overrides */
function planFormPayload(array $overrides = []): array
{
    return [
        'name' => 'Growth', 'code' => 'GROWTH', 'property_limit' => 10, 'trial_days' => 30,
        'grace_days' => 7, 'billing_period' => 'yearly', 'price' => 99000, 'currency' => 'USD', 'is_default' => false, ...$overrides,
    ];
}

test('a platform administrator creates a plan, audited under platform', function () {
    $admin = platformStaff();

    $this->actingAs($admin)->post(route('platform.plans.store'), planFormPayload())
        ->assertRedirect(route('platform.plans.index'));

    $plan = Plan::query()->where('code', 'GROWTH')->sole();
    expect($plan)
        ->property_limit->toBe(10)->trial_days->toBe(30)
        ->billing_period->toBe(BillingPeriod::Yearly)->modules->toBe([])->is_default->toBeFalse()
        ->and(Activity::query()->where('log_name', 'platform')->where('event', 'plan_created')->where('causer_id', $admin->id)->exists())->toBeTrue();

    $this->actingAs($admin)->get(route('platform.plans.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('platform/plans/index')->has('plans', 2));
});

test('a platform administrator edits a plan and can make it the default', function () {
    $admin = platformStaff();
    $essential = Plan::default();
    $this->actingAs($admin)->post(route('platform.plans.store'), planFormPayload());
    $growth = Plan::query()->where('code', 'GROWTH')->sole();

    $this->actingAs($admin)->put(route('platform.plans.update', $growth), planFormPayload(['property_limit' => null, 'is_default' => true]))
        ->assertSessionHasNoErrors();

    expect($growth->fresh())->property_limit->toBeNull()->is_default->toBeTrue()
        ->and($essential->fresh()->is_default)->toBeFalse()
        ->and(Activity::query()->where('event', 'plan_updated')->where('subject_id', $growth->id)->exists())->toBeTrue();
});

test('the default plan stays default until another plan takes over', function () {
    $plan = Plan::default();

    $this->actingAs(platformStaff())
        ->put(route('platform.plans.update', $plan), planFormPayload(['code' => $plan->code, 'is_default' => false]))
        ->assertSessionHasErrors('is_default');

    expect($plan->fresh()->is_default)->toBeTrue();
    expect(Activity::query()->where('event', 'refused')->where('subject_id', $plan->id)
        ->where('properties->errors->is_default', '!=', null)->exists())->toBeTrue();
});

test('plan details are validated', function () {
    $this->actingAs(platformStaff())
        ->post(route('platform.plans.store'), planFormPayload(['code' => Plan::default()->code, 'property_limit' => 0, 'billing_period' => 'weekly']))
        ->assertSessionHasErrors(['code', 'property_limit', 'billing_period']);
});

test('organization members, owners included, are refused the plan pages', function () {
    [$owner] = organizationWithOwner();

    $this->actingAs($owner)->get(route('platform.plans.index'))->assertForbidden();
    $this->actingAs($owner)->post(route('platform.plans.store'), planFormPayload())->assertForbidden();
    $this->put(route('platform.plans.update', Plan::default()), planFormPayload())->assertForbidden();
    expect(Activity::query()->where('event', 'refused')
        ->where('properties->action', 'platform.plans.update')->where('causer_id', $owner->id)->exists())->toBeTrue();
    $this->actingAs(platformStaff(PlatformRole::ComplianceOperator))
        ->get(route('platform.plans.index'))->assertForbidden();

    expect(Plan::query()->where('code', 'GROWTH')->exists())->toBeFalse();
});

test('creating an organization starts a trial on the default plan for its trial length', function () {
    $this->freezeTime();
    $plan = Plan::default();
    $plan->forceFill(['trial_days' => 21])->save();

    [$owner, $organization] = organizationWithOwner();

    $subscription = inTenant($organization, fn () => Subscription::current());
    expect($subscription)
        ->plan_id->toBe($plan->id)
        ->status->toBe(SubscriptionStatus::Trial)
        ->and($subscription->trial_ends_at->toIso8601String())->toBe(now()->addDays(21)->toIso8601String());

    $change = inTenant($organization, fn () => SubscriptionStateChange::query()->sole());
    expect($change)->from->toBeNull()->to->toBe(SubscriptionStatus::Trial)->actor_id->toBe($owner->id)
        ->and($change->reason)->not->toBe('');
});

test('existing organizations receive a trial subscription by migration', function () {
    [, $organization] = organizationWithOwner();
    $slips = require database_path('migrations/2026_10_31_120000_create_saas_payment_slips_table.php');
    $slips->down();
    $migrations = array_map(fn (string $path) => require $path, glob(database_path('migrations/*_subscriptions_*.php')));
    foreach (array_reverse($migrations) as $migration) {
        $migration->down();
    }

    foreach ($migrations as $migration) {
        $migration->up();
    }
    $slips->up();

    $subscription = inTenant($organization, fn () => Subscription::current());
    expect($subscription->status)->toBe(SubscriptionStatus::Trial)
        ->and($subscription->plan->is_default)->toBeTrue()
        ->and(inTenant($organization, fn () => SubscriptionStateChange::query()->sole()->reason))->not->toBe('');
});

test('the owner sees plan, limits, real usage and state', function () {
    $t = twoTenants();
    inTenant($t->a->property, function () {
        $type = app(CreateRoomType::class)->handle(null, InventoryForm::roomType());
        app(CreateRoom::class)->handle(null, InventoryForm::room($type->id));
        app(DeactivateRoom::class)->handle(null, app(CreateRoom::class)->handle(null, InventoryForm::room($type->id, ['number' => '102'])));
    });
    inTenant($t->a->otherProperty, fn () => app(ArchiveProperty::class)->handle(null, $t->a->otherProperty));
    $plan = Plan::default();

    $this->actingAs($t->a->owner)->get(route('subscription.show'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('subscription/show')
            ->where('subscription.status', 'trial')
            ->where('plan.name', $plan->name)
            ->where('plan.propertyLimit', $plan->property_limit)
            ->where('subscription.rooms', 150)
            ->where('usage', ['properties' => 1, 'rooms' => 1])
            ->has('history', 1));
});

test('members without organization management are refused the subscription page', function () {
    $t = twoTenants();

    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get(route('subscription.show'))->assertForbidden();
});

test('a state change is recorded with its reason and audited; a change needs a reason', function () {
    [$owner, $organization] = organizationWithOwner();
    $subscription = inTenant($organization, fn () => Subscription::current());

    inTenant($organization, fn () => app(TransitionSubscription::class)->handle($subscription, SubscriptionStatus::Active, 'First invoice paid', $owner));

    $change = inTenant($organization, fn () => $subscription->stateChanges()->first());
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($change)->from->toBe(SubscriptionStatus::Trial)->to->toBe(SubscriptionStatus::Active)->reason->toBe('First invoice paid')
        ->and(Activity::query()->where('event', 'subscription_status_changed')->where('subject_id', $subscription->id)
            ->where('properties->reason', 'First invoice paid')->exists())->toBeTrue();

    expect(fn () => inTenant($organization, fn () => app(TransitionSubscription::class)->handle($subscription, SubscriptionStatus::PastDue, ' ')))
        ->toThrow(LogicException::class);
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
});

test('the owner changes subscribed rooms, never below the rooms already active, and both are audited', function () {
    $t = twoTenants();
    inTenant($t->a->property, function () {
        $type = app(CreateRoomType::class)->handle(null, InventoryForm::roomType());
        app(CreateRoom::class)->handle(null, InventoryForm::room($type->id));
        app(CreateRoom::class)->handle(null, InventoryForm::room($type->id, ['number' => '102']));
    });
    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id]);

    $this->put(route('subscription.rooms.update'), ['rooms' => 1])->assertSessionHasErrors('rooms');
    $this->put(route('subscription.rooms.update'), ['rooms' => 2])->assertRedirect(route('subscription.show'));

    expect(inTenant($t->a->organization, fn () => Subscription::current()->rooms))->toBe(2)
        ->and(inTenant($t->b->organization, fn () => Subscription::current()->rooms))->toBe(150)
        ->and(Activity::query()->where('description', 'Subscribed rooms change refused')->sole()->getExtraProperty('result'))->toBe('refused')
        ->and(Activity::query()->where('event', 'subscribed_rooms_changed')->sole()->getExtraProperty('attributes'))->toBe(['rooms' => 2]);
});

test('a platform administrator sets an organization\'s subscribed rooms; organization members cannot', function () {
    $t = twoTenants();

    $this->actingAs(platformStaff())->put(route('platform.organizations.rooms.update', $t->b->organization->id), ['rooms' => 40])
        ->assertRedirect(route('platform.organizations.show', $t->b->organization->id));
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->withSession(['tenancy.organization_id' => $t->a->organization->id])
        ->put(route('subscription.rooms.update'), ['rooms' => 999])->assertForbidden();

    expect(inTenant($t->b->organization, fn () => Subscription::current()->rooms))->toBe(40)
        ->and(inTenant($t->a->organization, fn () => Subscription::current()->rooms))->toBe(150);
});
