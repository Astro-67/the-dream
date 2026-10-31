<?php

use App\Domains\Platform\Enums\PlatformRole;
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

test('approval is refused unless the subscription is Active', function (string $status, string $label) {
    $t = twoTenants();
    $property = waitingForApproval($t, $status);

    $this->actingAs(platformStaff())->post(route('platform.properties.go-live.approve', $property->id))
        ->assertSessionHasErrors(['go_live' => "The subscription is {$label}. Go-live needs an Active subscription."]);

    expect($property->fresh()->status)->toBe(PropertyStatus::Setup)
        ->and(Activity::query()->where('event', 'refused')->where('subject_id', $property->id)->sole()->description)->toBe('Go-live approval refused');
})->with([
    ['trial', 'Trial'],
    ['past_due', 'Past due'],
    ['suspended', 'Suspended'],
    ['cancelled', 'Cancelled'],
]);

test('approval is refused, naming the failing gates, when a gate fails after the request', function () {
    $t = twoTenants();
    $property = waitingForApproval($t);
    $property->forceFill(['taxes_reviewed_at' => null])->save();

    $this->actingAs(platformStaff())->post(route('platform.properties.go-live.approve', $property->id))
        ->assertSessionHasErrors(['go_live' => 'Not ready to go live. Still to do: Taxes reviewed.']);

    expect($property->fresh()->status)->toBe(PropertyStatus::Setup)
        ->and(Activity::query()->where('event', 'refused')->where('subject_id', $property->id)->count())->toBe(1);
});

test('approval is refused when no go-live request waits', function () {
    $t = twoTenants();
    $property = $t->a->property;
    $property->forceFill(['status' => PropertyStatus::Setup, 'went_live_at' => null, 'taxes_reviewed_at' => now()])->save();
    inTenant($property, fn () => BookingForm::details($t->a->owner));
    vfmsLive($property);
    inTenant($t->a->organization, fn () => Subscription::current()->update(['status' => 'active']));

    $this->actingAs(platformStaff())->post(route('platform.properties.go-live.approve', $property->id))
        ->assertSessionHasErrors(['go_live' => 'This property has no go-live request waiting.']);

    expect($property->fresh()->status)->toBe(PropertyStatus::Setup);
});

test('only platform administrators with two-factor authentication can approve go-live', function () {
    $t = twoTenants();
    $property = waitingForApproval($t);
    $url = route('platform.properties.go-live.approve', $property->id);

    foreach ($t->a->members() as $member) {
        $this->actingAs($member)->post($url)->assertForbidden();
    }
    $this->actingAs(platformStaff(PlatformRole::ComplianceOperator))->post($url)->assertForbidden();
    $this->actingAs(platformStaff(twoFactor: false))->post($url)->assertRedirect(route('security.edit'));
    $this->actingAs(platformStaff())->post(route('platform.properties.go-live.approve', 'missing'))->assertNotFound();

    expect($property->fresh()->status)->toBe(PropertyStatus::Setup);
});
