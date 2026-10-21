<?php

use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\ReservationLifecycleEvent;
use App\Domains\Tenancy\Actions\CreateProperty;
use App\Domains\Tenancy\Actions\GoLive;
use App\Domains\Tenancy\Data\PropertyDetails;
use App\Domains\Tenancy\Enums\JurisdictionProfile;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Enums\PropertyStatus;
use App\Domains\Tenancy\Models\Property;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;
use Tests\Support\TenantFixture;

/** A new property in setup, added to Alpha by its owner. */
function setupProperty(TenantFixture $tenant): Property
{
    return inTenant($tenant->organization, fn () => app(CreateProperty::class)->handle($tenant->owner, PropertyDetails::fromArray(
        organizationSetupPayload(['property_name' => $tenant->label.' Setup House', 'email' => 'setup@example.test']),
        'property_name',
    )));
}

test('a new property starts in setup', function () {
    $t = twoTenants();

    expect(setupProperty($t->a)->fresh()->status)->toBe(PropertyStatus::Setup);
});

test('go-live is refused while gates fail, naming each failing gate', function () {
    $t = twoTenants();
    $property = setupProperty($t->a);

    $this->actingAs($t->a->owner)
        ->post(route('properties.go-live', $property), ['_property' => $property->id])
        ->assertSessionHasErrors(['go_live' => 'Not ready to go live. Still to do: At least one room type, At least one room, At least one rate plan, Taxes reviewed, Z-number, Star rating.']);

    expect($property->fresh()->status)->toBe(PropertyStatus::Setup)
        ->and(Activity::query()->where('event', 'refused')->where('subject_id', $property->id)->exists())->toBeTrue();
});

test('a property manager reviews taxes and takes the property live once gates pass, audited with actor and time', function () {
    $t = twoTenants();
    $property = $t->a->property;
    $property->forceFill(['status' => PropertyStatus::Setup])->save();
    inTenant($property, fn () => BookingForm::details($t->a->owner));
    $manager = $t->a->member(OrganizationRole::PropertyManager);

    $this->actingAs($manager)->post(route('properties.taxes-reviewed', $property), ['_property' => $property->id])->assertRedirect();
    $this->actingAs($manager)->post(route('properties.go-live', $property), ['_property' => $property->id])->assertSessionHasNoErrors();

    $property->refresh();
    expect($property->status)->toBe(PropertyStatus::Live)
        ->and($property->went_live_by)->toBe($manager->id)
        ->and($property->went_live_at)->not->toBeNull()
        ->and($property->taxes_reviewed_by)->toBe($manager->id);
    $audit = Activity::query()->where('event', 'went_live')->sole();
    expect($audit->causer_id)->toBe($manager->id)->and($audit->subject_id)->toBe($property->id);
});

test('Zanzibar go-live refuses missing registration and shows the failing gates on the checklist', function (?int $stars, ?string $zNumber, string $missing) {
    $t = twoTenants();
    $property = $t->a->property;
    $property->forceFill([
        'status' => PropertyStatus::Setup,
        'star_rating' => $stars,
        'z_number' => $zNumber,
        'taxes_reviewed_at' => now(),
    ])->save();
    $t->a->otherProperty->forceFill(['star_rating' => 5, 'z_number' => 'Z-OTHER'])->save();
    inTenant($property, fn () => BookingForm::details($t->a->owner));

    $this->actingAs($t->a->owner)->put(route('current-property.update'), ['property_id' => $property->id])->assertRedirect();
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->has('onboarding.gates', 6)
        ->where('onboarding.gates.4.key', 'z_number')
        ->where('onboarding.gates.4.passed', filled($zNumber))
        ->where('onboarding.gates.5.key', 'star_rating')
        ->where('onboarding.gates.5.passed', $stars !== null)
        ->where('onboarding.canGoLive', false));

    $this->post(route('properties.go-live', $property), ['_property' => $property->id])
        ->assertSessionHasErrors(['go_live' => "Not ready to go live. Still to do: {$missing}."]);

    expect($property->fresh()->status)->toBe(PropertyStatus::Setup)
        ->and(Activity::query()->where('event', 'refused')->where('subject_id', $property->id)->exists())->toBeTrue();
})->with([
    'both missing' => [null, null, 'Z-number, Star rating'],
    'Z-number missing' => [3, null, 'Z-number'],
    'Z-number empty' => [3, '', 'Z-number'],
    'Z-number whitespace' => [3, '   ', 'Z-number'],
    'not yet classified' => [null, 'Z-122', 'Star rating'],
]);

test('registration gates allow classified Zanzibar properties and leave mainland go-live unaffected', function (JurisdictionProfile $profile, ?int $stars, ?string $zNumber, int $gateCount) {
    $t = twoTenants();
    $property = $t->a->property;
    $property->forceFill([
        'status' => PropertyStatus::Setup,
        'jurisdiction_profile' => $profile,
        'star_rating' => $stars,
        'z_number' => $zNumber,
        'tin' => null,
        'vrn' => null,
        'taxes_reviewed_at' => now(),
    ])->save();
    inTenant($property, fn () => BookingForm::details($t->a->owner));

    $this->actingAs($t->a->owner)->put(route('current-property.update'), ['property_id' => $property->id])->assertRedirect();
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->has('onboarding.gates', $gateCount)
        ->where('onboarding.canGoLive', true));
    $this->post(route('properties.go-live', $property), ['_property' => $property->id])->assertSessionHasNoErrors();

    expect($property->fresh()->status)->toBe(PropertyStatus::Live);
})->with([
    'Zanzibar' => [JurisdictionProfile::Zanzibar, 1, 'Z-122', 6],
    'mainland without ZRA registration' => [JurisdictionProfile::MainlandTanzania, null, null, 4],
]);

test('go-live gates do not borrow rooms rates or tax review from another property in the organization', function () {
    $t = twoTenants();
    $property = $t->a->property;
    $property->forceFill(['status' => PropertyStatus::Setup])->save();
    inTenant($t->a->otherProperty, fn () => BookingForm::details($t->a->owner));
    $this->actingAs($t->a->owner)->post(route('properties.taxes-reviewed', $t->a->otherProperty), [
        '_property' => $t->a->otherProperty->id,
    ])->assertSessionHasNoErrors();

    $this->post(route('properties.go-live', $property), ['_property' => $property->id])
        ->assertSessionHasErrors(['go_live' => 'Not ready to go live. Still to do: At least one room type, At least one room, At least one rate plan, Taxes reviewed.']);
    expect($property->fresh()->status)->toBe(PropertyStatus::Setup);

    inTenant($property, fn () => BookingForm::details($t->a->owner));
    $this->post(route('properties.taxes-reviewed', $property), ['_property' => $property->id])
        ->assertSessionHasNoErrors();
    $this->post(route('properties.go-live', $property), ['_property' => $property->id])
        ->assertSessionHasNoErrors();
    expect($property->fresh()->status)->toBe(PropertyStatus::Live);
});

test('staff without property update permission cannot take a property live', function (OrganizationRole $role) {
    $t = twoTenants();
    $t->a->property->forceFill(['status' => PropertyStatus::Setup])->save();

    $this->actingAs($t->a->member($role))
        ->post(route('properties.go-live', $t->a->property), ['_property' => $t->a->property->id])
        ->assertForbidden();
    expect($t->a->property->fresh()->status)->toBe(PropertyStatus::Setup);
    expect(Activity::query()->where('event', 'refused')->where('subject_id', $t->a->property->id)
        ->where('properties->action', 'properties.go-live')->exists())->toBeTrue();
})->with([OrganizationRole::FrontDesk, OrganizationRole::Finance, OrganizationRole::Housekeeping, OrganizationRole::Auditor]);

test('a property in setup takes test reservations but refuses check-in', function () {
    $t = twoTenants();
    $property = $t->a->property;
    $property->forceFill(['status' => PropertyStatus::Setup, 'business_date' => '2026-12-10'])->save();

    inTenant($property, function () use ($t) {
        $details = BookingForm::details($t->a->owner);
        $reservation = app(CreateReservation::class)->handle($t->a->owner, [...$details, 'room_id' => Room::query()->sole()->id]);

        expect(ReservationLifecycleEvent::query()->where('reservation_id', $reservation->id)->sole()->test)->toBeTrue();
        expect(fn () => app(CheckIn::class)->handle($t->a->owner, $reservation))
            ->toThrow(ValidationException::class, 'This property is still in setup. Guests can be checked in once it goes live.');
    });
});

test('lifecycle events at a live property are not test events', function () {
    $t = twoTenants();

    inTenant($t->a->property, function () use ($t) {
        $reservation = app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));

        expect(ReservationLifecycleEvent::query()->where('reservation_id', $reservation->id)->sole()->test)->toBeFalse();
    });
});

test('going live again changes nothing', function () {
    $t = twoTenants();
    $before = $t->a->property->fresh()->only(['status', 'went_live_at']);

    inTenant($t->a->property, fn () => app(GoLive::class)->handle($t->a->owner, $t->a->property));

    expect($t->a->property->fresh()->only(['status', 'went_live_at']))->toEqual($before);
});
