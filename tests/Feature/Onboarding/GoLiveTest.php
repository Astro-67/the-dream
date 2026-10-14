<?php

use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\ReservationLifecycleEvent;
use App\Domains\Tenancy\Actions\CreateProperty;
use App\Domains\Tenancy\Actions\GoLive;
use App\Domains\Tenancy\Data\PropertyDetails;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Enums\PropertyStatus;
use App\Domains\Tenancy\Models\Property;
use Illuminate\Validation\ValidationException;
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
        ->assertSessionHasErrors(['go_live' => 'Not ready to go live. Still to do: At least one room type, At least one room, At least one rate plan, Taxes reviewed.']);

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

test('staff without property update permission cannot take a property live', function (OrganizationRole $role) {
    $t = twoTenants();
    $t->a->property->forceFill(['status' => PropertyStatus::Setup])->save();

    $this->actingAs($t->a->member($role))
        ->post(route('properties.go-live', $t->a->property), ['_property' => $t->a->property->id])
        ->assertForbidden();
    expect($t->a->property->fresh()->status)->toBe(PropertyStatus::Setup);
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
