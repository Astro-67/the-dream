<?php

use App\Domains\Tenancy\Enums\PropertyStatus;
use Inertia\Testing\AssertableInertia as Assert;

test('the selected setup property shows a checklist derived from its data', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->forceFill(['status' => PropertyStatus::Setup])->save();

    $this->actingAs($owner)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('onboarding.propertyId', $property->id)
            ->where('onboarding.items.0.key', 'verify_email')
            ->where('onboarding.items.0.completed', true)
            ->where('onboarding.items.1.key', 'property_profile')
            ->where('onboarding.items.1.completed', true)
            ->where('onboarding.items.2.key', 'rooms')
            ->where('onboarding.items.2.completed', false)
            ->where('onboarding.items.3.key', 'rates')
            ->where('onboarding.items.3.completed', false)
            ->where('onboarding.items.4.key', 'taxes_reviewed')
            ->where('onboarding.items.4.completed', false)
            ->where('onboarding.items.5.key', 'invite_staff')
            ->where('onboarding.items.5.completed', false)
            ->where('onboarding.items.6.key', 'import_reservations')
            ->where('onboarding.items.6.completed', false)
            ->where('onboarding.items.7.key', 'sample_reservation')
            ->where('onboarding.items.7.completed', false)
            ->has('onboarding.gates', 4)
            ->where('onboarding.canGoLive', false));
});
