<?php

use App\Domains\Platform\Enums\PlatformRole;
use App\Domains\Tenancy\Enums\PropertyStatus;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

test('a platform administrator declines a waiting go-live request with an audited reason', function () {
    $tenants = twoTenants();
    $property = $tenants->b->property;
    $property->forceFill([
        'status' => PropertyStatus::Setup,
        'go_live_requested_at' => now(),
        'go_live_requested_by' => $tenants->b->owner->id,
    ])->save();
    $admin = platformStaff();

    $this->actingAs($admin)
        ->from(route('platform.organizations.show', $property->organization_id))
        ->post(route('platform.properties.decline-go-live', $property->id), ['reason' => 'Please correct the business address.'])
        ->assertRedirect(route('platform.organizations.show', $property->organization_id))
        ->assertSessionHasNoErrors();

    expect($property->fresh())
        ->status->toBe(PropertyStatus::Setup)
        ->go_live_requested_at->toBeNull()
        ->go_live_requested_by->toBeNull()
        ->go_live_declined_reason->toBe('Please correct the business address.');

    $audit = Activity::query()->where('event', 'go_live_declined')->where('subject_id', $property->id)->sole();
    expect($audit->causer_id)->toBe($admin->id)
        ->and($audit->getExtraProperty('reason'))->toBe('Please correct the business address.')
        ->and($audit->getExtraProperty('organization_id'))->toBe($property->organization_id)
        ->and($audit->getExtraProperty('property_id'))->toBe($property->id);
});

test('declining requires a nonblank bounded reason without changing the request', function (mixed $reason) {
    $property = twoTenants()->a->property;
    $property->forceFill(['status' => PropertyStatus::Setup, 'go_live_requested_at' => now()])->save();
    $before = $property->fresh()->getAttributes();

    $this->actingAs(platformStaff())
        ->post(route('platform.properties.decline-go-live', $property->id), ['reason' => $reason])
        ->assertSessionHasErrors('reason');

    expect($property->fresh()->getAttributes())->toEqual($before);
    expect(Activity::query()->where('event', 'refused')->where('properties->action', 'platform.properties.decline-go-live')->exists())->toBeTrue();
})->with([null, '', '   ', [['not a string']], str_repeat('x', 2001)]);

test('only platform administrators with two-factor authentication may decline', function () {
    $tenants = twoTenants();
    $property = $tenants->b->property;
    $property->forceFill(['status' => PropertyStatus::Setup, 'go_live_requested_at' => now()])->save();
    $before = $property->fresh()->getAttributes();

    foreach ([...$tenants->a->members(), platformStaff(PlatformRole::ComplianceOperator)] as $user) {
        $this->actingAs($user)->post(route('platform.properties.decline-go-live', $property->id), ['reason' => 'Not ready.'])->assertForbidden();
    }
    $this->actingAs(platformStaff(twoFactor: false))
        ->post(route('platform.properties.decline-go-live', $property->id), ['reason' => 'Not ready.'])
        ->assertRedirect(route('security.edit'));

    expect($property->fresh()->getAttributes())->toEqual($before);
});

test('a decline refuses properties without a waiting request and preserves their state', function (string $state) {
    $property = twoTenants()->a->property;
    $property->forceFill([
        'status' => $state === 'live' ? PropertyStatus::Live : PropertyStatus::Setup,
        'go_live_requested_at' => in_array($state, ['live', 'archived']) ? now() : null,
        'archived_at' => $state === 'archived' ? now() : null,
        'go_live_declined_reason' => $state === 'declined' ? 'Original reason.' : null,
    ])->save();
    $before = $property->fresh()->getAttributes();

    $this->actingAs(platformStaff())
        ->post(route('platform.properties.decline-go-live', $property->id), ['reason' => 'Replacement reason.'])
        ->assertSessionHasErrors('reason');

    expect($property->fresh()->getAttributes())->toEqual($before);
    expect(Activity::query()->where('event', 'refused')->where('subject_id', $property->id)->exists())->toBeTrue();
})->with(['setup', 'live', 'archived', 'declined']);

test('declining an unknown property returns not found', function () {
    $this->actingAs(platformStaff())
        ->post(route('platform.properties.decline-go-live', '01AAAAAAAAAAAAAAAAAAAAAAAA'), ['reason' => 'Not ready.'])
        ->assertNotFound();
});

test('the owner sees the decline reason on the property and onboarding screens', function () {
    $tenants = twoTenants();
    $property = $tenants->a->property;
    $property->forceFill(['status' => PropertyStatus::Setup, 'go_live_requested_at' => now()])->save();
    $this->actingAs(platformStaff())
        ->post(route('platform.properties.decline-go-live', $property->id), ['reason' => 'Correct the business address.'])
        ->assertSessionHasNoErrors();

    $this->actingAs($tenants->a->owner)->get(route('properties.index'))
        ->assertInertia(fn (Assert $page) => $page->where('properties', fn ($properties) => collect($properties)
            ->firstWhere('id', $property->id)['goLiveDeclinedReason'] === 'Correct the business address.'));
    $this->withSession(['tenancy.organization_id' => $property->organization_id, 'tenancy.property_id' => $property->id])->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('onboarding.goLiveRequested', false)
            ->where('onboarding.goLiveDeclinedReason', 'Correct the business address.'));
});
