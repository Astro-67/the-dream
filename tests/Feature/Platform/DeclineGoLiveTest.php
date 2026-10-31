<?php

use App\Domains\Tenancy\Enums\PropertyStatus;
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
