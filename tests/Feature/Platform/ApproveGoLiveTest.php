<?php

use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Tenancy\Enums\PropertyStatus;
use App\Domains\Tenancy\Models\Property;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;
use Tests\Support\TwoTenants;

/** Alpha's property in setup with every gate passing and a go-live request waiting; the subscription is $status. */
function waitingForApproval(TwoTenants $t, string $status = 'active'): Property
{
    $property = $t->a->property;
    $property->forceFill(['status' => PropertyStatus::Setup, 'went_live_at' => null, 'taxes_reviewed_at' => now()])->save();
    inTenant($property, fn () => BookingForm::details($t->a->owner));
    vfmsLive($property);
    test()->actingAs($t->a->owner)->post(route('properties.go-live', $property), ['_property' => $property->id])->assertSessionHasNoErrors();
    inTenant($t->a->organization, fn () => Subscription::current()->update(['status' => $status]));

    return $property;
}

test('a platform administrator approves a waiting go-live request and the property goes live', function () {
    $t = twoTenants();
    $property = waitingForApproval($t);
    $admin = platformStaff();

    $this->actingAs($admin)->post(route('platform.properties.go-live.approve', $property->id))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('platform.organizations.show', $t->a->organization->id));

    $property->refresh();
    expect($property->status)->toBe(PropertyStatus::Live)
        ->and($property->went_live_by)->toBe($admin->id)
        ->and($property->went_live_at)->not->toBeNull();
    $audit = Activity::query()->where('event', 'went_live')->sole();
    expect($audit->causer_id)->toBe($admin->id)->and($audit->subject_id)->toBe($property->id);
});
