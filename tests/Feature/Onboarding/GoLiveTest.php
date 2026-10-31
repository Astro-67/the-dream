<?php

use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\Inventory\Models\Room;
use App\Domains\Rates\Actions\SeedDefaultTaxRules;
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
use Tests\Support\PropertyForm;
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

test('Zanzibar go-live refuses each missing applicable registration value', function (string $field, mixed $value, string $label) {
    $t = twoTenants();
    $property = $t->a->property;
    $property->forceFill([
        'status' => PropertyStatus::Setup,
        'taxes_reviewed_at' => now(),
        'tin' => 'TIN-TEST',
        'z_number' => 'Z-TEST/LOCATION',
        'vrn' => 'VRN-TEST',
        'zra_hotel_code' => 'HOTEL-TEST',
        'star_rating' => 3,
        $field => $value,
    ])->save();
    inTenant($property, fn () => BookingForm::details($t->a->owner));
    vfmsLive($property);

    $this->actingAs($t->a->owner)
        ->post(route('properties.go-live', $property), ['_property' => $property->id])
        ->assertSessionHasErrors(['go_live' => "Not ready to go live. Still to do: {$label}."]);

    expect($property->fresh()->status)->toBe(PropertyStatus::Setup)
        ->and(Activity::query()->where('event', 'refused')->where('subject_id', $property->id)->exists())->toBeTrue();
})->with([
    ['tin', null, 'TIN'],
    ['tin', '   ', 'TIN'],
    ['z_number', null, 'Z-number'],
    ['vrn', null, 'VRN'],
    ['vrn', '   ', 'VRN'],
    ['zra_hotel_code', null, 'ZRA hotel code'],
    ['zra_hotel_code', '   ', 'ZRA hotel code'],
    ['star_rating', null, 'Star rating'],
    ['star_rating', 0, 'Star rating'],
    ['star_rating', 6, 'Star rating'],
]);

test('a Zanzibar property without complete VFMS credentials, or in Sandbox, fails the VFMS gate', function (array $credential) {
    $t = twoTenants();
    $property = $t->a->property;
    $property->forceFill(['status' => PropertyStatus::Setup, 'taxes_reviewed_at' => now()])->save();
    inTenant($property, fn () => BookingForm::details($t->a->owner));
    if ($credential !== []) {
        inTenant($property, fn () => VfmsCredential::query()->forceCreate($credential));
    }

    $this->actingAs($t->a->owner)->put(route('current-property.update'), ['property_id' => $property->id])->assertRedirect();
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('onboarding.gates.8.key', 'vfms')
        ->where('onboarding.gates.8.passed', false)
        ->where('onboarding.canGoLive', false));
    $this->post(route('properties.go-live', $property), ['_property' => $property->id])
        ->assertSessionHasErrors(['go_live' => 'Not ready to go live. Still to do: VFMS credentials accepted by ZRA and set to Live.']);

    expect($property->fresh()->go_live_requested_at)->toBeNull();
})->with([
    'nothing saved' => [[]],
    'Sandbox with complete credentials' => [['integration_id' => 'INT-1', 'registration_id' => 'REG-1', 'token' => 'tok', 'mode' => 'sandbox']],
    'Live without a token' => [['integration_id' => 'INT-1', 'registration_id' => 'REG-1', 'mode' => 'live', 'zra_check' => 'accepted']],
    'Live, not yet checked with ZRA' => [['integration_id' => 'INT-1', 'registration_id' => 'REG-1', 'token' => 'tok', 'mode' => 'live']],
    'Live, refused by ZRA' => [['integration_id' => 'INT-1', 'registration_id' => 'REG-1', 'token' => 'tok', 'mode' => 'live', 'zra_check' => 'refused']],
    'Live, ZRA unreachable' => [['integration_id' => 'INT-1', 'registration_id' => 'REG-1', 'token' => 'tok', 'mode' => 'live', 'zra_check' => 'unreachable']],
]);

test('go-live is refused while gates fail, naming each failing gate', function () {
    $t = twoTenants();
    $property = setupProperty($t->a);

    $this->actingAs($t->a->owner)
        ->post(route('properties.go-live', $property), ['_property' => $property->id])
        ->assertSessionHasErrors(['go_live' => 'Not ready to go live. Still to do: At least one room type, At least one room, At least one rate plan, Taxes reviewed, TIN, Z-number, Star rating, VFMS credentials accepted by ZRA and set to Live, VRN.']);

    expect($property->fresh()->status)->toBe(PropertyStatus::Setup)
        ->and(Activity::query()->where('event', 'refused')->where('subject_id', $property->id)->exists())->toBeTrue();
});

test('a property manager reviews taxes and requests go-live once gates pass; the property stays in setup', function () {
    $t = twoTenants();
    $property = $t->a->property;
    $property->forceFill(['status' => PropertyStatus::Setup])->save();
    inTenant($property, fn () => BookingForm::details($t->a->owner));
    vfmsLive($property);
    $manager = $t->a->member(OrganizationRole::PropertyManager);

    $this->actingAs($manager)->post(route('properties.taxes-reviewed', $property), ['_property' => $property->id])->assertRedirect();
    $this->actingAs($manager)->post(route('properties.go-live', $property), ['_property' => $property->id])->assertSessionHasNoErrors();

    $property->refresh();
    expect($property->status)->toBe(PropertyStatus::Setup)
        ->and($property->went_live_at)->toBeNull()
        ->and($property->go_live_requested_by)->toBe($manager->id)
        ->and($property->go_live_requested_at)->not->toBeNull()
        ->and($property->taxes_reviewed_by)->toBe($manager->id);
    $audit = Activity::query()->where('event', 'go_live_requested')->sole();
    expect($audit->causer_id)->toBe($manager->id)->and($audit->subject_id)->toBe($property->id)
        ->and(Activity::query()->where('event', 'went_live')->exists())->toBeFalse();
});

test('requesting go-live again changes nothing', function () {
    $t = twoTenants();
    $property = $t->a->property;
    $property->forceFill(['status' => PropertyStatus::Setup, 'taxes_reviewed_at' => now()])->save();
    inTenant($property, fn () => BookingForm::details($t->a->owner));
    vfmsLive($property);

    $this->actingAs($t->a->owner)->post(route('properties.go-live', $property), ['_property' => $property->id])->assertSessionHasNoErrors();
    $before = $property->fresh()->only(['status', 'go_live_requested_at', 'go_live_requested_by']);
    $this->travel(1)->hour();
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->post(route('properties.go-live', $property), ['_property' => $property->id])->assertSessionHasNoErrors();

    expect($property->fresh()->only(['status', 'go_live_requested_at', 'go_live_requested_by']))->toEqual($before)
        ->and(Activity::query()->where('event', 'go_live_requested')->count())->toBe(1);
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
    vfmsLive($property);

    $this->actingAs($t->a->owner)->put(route('current-property.update'), ['property_id' => $property->id])->assertRedirect();
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->has('onboarding.gates', 10)
        ->where('onboarding.gates.5.key', 'z_number')
        ->where('onboarding.gates.5.passed', filled($zNumber))
        ->where('onboarding.gates.7.key', 'star_rating')
        ->where('onboarding.gates.7.passed', $stars !== null)
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
        'tin' => $profile === JurisdictionProfile::Zanzibar ? 'TIN-TEST' : null,
        'vrn' => $profile === JurisdictionProfile::Zanzibar ? 'VRN-TEST' : null,
        'zra_hotel_code' => $profile === JurisdictionProfile::Zanzibar ? 'HOTEL-TEST' : null,
        'taxes_reviewed_at' => now(),
    ])->save();
    inTenant($property, fn () => BookingForm::details($t->a->owner));
    if ($profile === JurisdictionProfile::Zanzibar) {
        vfmsLive($property);
    }

    $this->actingAs($t->a->owner)->put(route('current-property.update'), ['property_id' => $property->id])->assertRedirect();
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->has('onboarding.gates', $gateCount)
        ->where('onboarding.canGoLive', true));
    $this->post(route('properties.go-live', $property), ['_property' => $property->id])->assertSessionHasNoErrors();

    expect($property->fresh()->go_live_requested_at)->not->toBeNull();
})->with([
    'Zanzibar' => [JurisdictionProfile::Zanzibar, 1, 'Z-122', 10],
    'mainland without ZRA registration' => [JurisdictionProfile::MainlandTanzania, null, null, 4],
]);

test('saved tax category decides whether Zanzibar go-live needs a VRN, not tax rules', function (string $category, bool $hasTaxRules) {
    $t = twoTenants();
    $property = $t->a->property;
    $property->forceFill(['status' => PropertyStatus::Setup, 'vrn' => null, 'taxes_reviewed_at' => now()])->save();
    if ($hasTaxRules) {
        inTenant($property, fn () => app(SeedDefaultTaxRules::class)->handle($t->a->owner, $property));
    }
    inTenant($property, fn () => BookingForm::details($t->a->owner));
    vfmsLive($property);
    $this->actingAs($t->a->owner)->put(route('current-property.update'), ['property_id' => $property->id])->assertRedirect();

    foreach (['vat', $category, 'vat', $category] as $selected) {
        $this->put(route('properties.update', $property), PropertyForm::payload([
            '_property' => $property->id,
            'jurisdiction_profile' => 'zanzibar',
            'zra_hotel_code' => $property->zra_hotel_code,
            'tax_settings' => ['zra_tax_category' => $selected],
        ]))->assertSessionHasNoErrors();
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->has('onboarding.gates', $selected === 'vat' ? 10 : 9)
            ->where('onboarding.canGoLive', $selected !== 'vat'));
    }

    $this->post(route('properties.go-live', $property), ['_property' => $property->id])->assertSessionHasNoErrors();
    expect($property->fresh()->go_live_requested_at)->not->toBeNull();
})->with(['hotel_levy', 'tour_operator', 'exempt'])->with([true, false]);

test('go-live gates do not borrow rooms rates tax review or VFMS credentials from another property in the organization', function () {
    $t = twoTenants();
    $property = $t->a->property;
    $property->forceFill(['status' => PropertyStatus::Setup])->save();
    inTenant($t->a->otherProperty, fn () => BookingForm::details($t->a->owner));
    vfmsLive($t->a->otherProperty);
    $this->actingAs($t->a->owner)->post(route('properties.taxes-reviewed', $t->a->otherProperty), [
        '_property' => $t->a->otherProperty->id,
    ])->assertSessionHasNoErrors();

    $this->post(route('properties.go-live', $property), ['_property' => $property->id])
        ->assertSessionHasErrors(['go_live' => 'Not ready to go live. Still to do: At least one room type, At least one room, At least one rate plan, Taxes reviewed, VFMS credentials accepted by ZRA and set to Live.']);
    expect($property->fresh()->status)->toBe(PropertyStatus::Setup);

    inTenant($property, fn () => BookingForm::details($t->a->owner));
    vfmsLive($property);
    $this->post(route('properties.taxes-reviewed', $property), ['_property' => $property->id])
        ->assertSessionHasNoErrors();
    $this->post(route('properties.go-live', $property), ['_property' => $property->id])
        ->assertSessionHasNoErrors();
    expect($property->fresh()->go_live_requested_at)->not->toBeNull();
});

test('staff without property update permission cannot request go-live', function (OrganizationRole $role) {
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

test('the property list shows a property waiting for go-live approval', function () {
    $t = twoTenants();
    $property = $t->a->property;
    $property->forceFill(['status' => PropertyStatus::Setup, 'taxes_reviewed_at' => now()])->save();
    inTenant($property, fn () => BookingForm::details($t->a->owner));
    vfmsLive($property);
    $requested = fn (Assert $page) => collect($page->toArray()['props']['properties'])->firstWhere('id', $property->id)['goLiveRequested'];

    $this->actingAs($t->a->owner)->get(route('properties.index'))->assertInertia(fn (Assert $page) => expect($requested($page))->toBeFalse());
    $this->post(route('properties.go-live', $property), ['_property' => $property->id])->assertSessionHasNoErrors();
    $this->get(route('properties.index'))->assertInertia(fn (Assert $page) => expect($requested($page))->toBeTrue());
});
