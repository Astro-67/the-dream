<?php

use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\ReservationLifecycleEvent;
use App\Domains\Tenancy\Actions\MarkTaxesReviewed;
use App\Domains\Tenancy\Actions\SendInvitation;
use App\Domains\Tenancy\Data\InvitationDetails;
use App\Domains\Tenancy\Data\MembershipGrant;
use App\Domains\Tenancy\Enums\JurisdictionProfile;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Enums\PropertyStatus;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;

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
            ->has('onboarding.items.2.items', 2)
            ->where('onboarding.items.2.items.0.key', 'room_type')
            ->where('onboarding.items.2.items.0.label', 'Add a room type')
            ->where('onboarding.items.2.items.0.completed', false)
            ->where('onboarding.items.2.items.0.href', route('room-types.create'))
            ->where('onboarding.items.2.items.1.key', 'room')
            ->where('onboarding.items.2.items.1.label', 'Add rooms')
            ->where('onboarding.items.2.items.1.completed', false)
            ->where('onboarding.items.2.items.1.href', route('rooms.create'))
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
            ->has('onboarding.gates', 10)
            ->where('onboarding.canGoLive', false));
});

test('room setup substeps track adding a room type and an active room', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->forceFill(['status' => PropertyStatus::Setup])->save();
    $this->actingAs($owner);

    $this->post(route('room-types.store'), InventoryForm::roomType(['_property' => $property->id]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('room-types.index'));
    $roomTypeId = $this->get(route('room-types.index'))->inertiaProps('roomTypes.0.id');

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('onboarding.items.2.completed', false)
        ->where('onboarding.items.2.items.0.completed', true)
        ->where('onboarding.items.2.items.1.completed', false));

    $this->post(route('rooms.store'), InventoryForm::room($roomTypeId, ['_property' => $property->id]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('rooms.index'));
    $roomId = $this->get(route('rooms.index'))->inertiaProps('rooms.0.id');

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('onboarding.items.2.completed', true)
        ->where('onboarding.items.2.items.0.completed', true)
        ->where('onboarding.items.2.items.1.completed', true));

    $this->post(route('rooms.deactivate', $roomId), ['_property' => $property->id])
        ->assertSessionHasNoErrors()
        ->assertRedirect();
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('onboarding.items.2.completed', false)
        ->where('onboarding.items.2.items.0.completed', true)
        ->where('onboarding.items.2.items.1.completed', false));
});

test('setup progress survives leaving and returning and offers go-live only after the gates pass', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->forceFill(['status' => PropertyStatus::Setup, 'business_date' => '2026-12-01'])->save();
    $this->actingAs($owner);

    $details = inTenant($property, fn () => BookingForm::details($owner));
    vfmsLive($property);
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('onboarding.items.2.completed', true)
        ->where('onboarding.items.3.completed', true)
        ->where('onboarding.items.3.href', route('rate-calendar'))
        ->where('onboarding.items.4.completed', false)
        ->where('onboarding.canGoLive', false));

    inTenant($property, function () use ($owner, $property, $details) {
        app(MarkTaxesReviewed::class)->handle($owner, $property);
        app(SendInvitation::class)->handle($owner, new InvitationDetails(
            'staff@example.test',
            MembershipGrant::properties(OrganizationRole::FrontDesk, [$property->id]),
            CarbonImmutable::now()->addWeek(),
        ));
        app(CreateReservation::class)->handle($owner, $details);
    });

    $before = $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('onboarding.items.4.completed', true)
        ->where('onboarding.items.5.completed', true)
        ->where('onboarding.items.7.completed', true)
        ->where('onboarding.goLiveRequested', false)
        ->where('onboarding.canGoLive', true));
    $this->get(route('properties.index'))->assertOk();
    $after = $this->get(route('dashboard'))->assertOk();

    expect($after->inertiaProps('onboarding'))->toEqual($before->inertiaProps('onboarding'));

    $this->post(route('properties.go-live', $property), ['_property' => $property->id])->assertSessionHasNoErrors();
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('onboarding.goLiveRequested', true)->where('onboarding.canGoLive', false));
});

test('only mainland setup properties show the non-fiscal receipt notice', function (JurisdictionProfile $profile, ?string $notice) {
    [$owner, , $property] = organizationWithOwner();
    $property->forceFill(['status' => PropertyStatus::Setup, 'jurisdiction_profile' => $profile])->save();

    $this->actingAs($owner)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('onboarding.receiptNotice', $notice));
})->with([
    'mainland' => [JurisdictionProfile::MainlandTanzania, 'Thedream issues non-fiscal receipts for mainland properties.'],
    'Zanzibar' => [JurisdictionProfile::Zanzibar, null],
]);

test('checklist links lead to the existing setup screens', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->forceFill(['status' => PropertyStatus::Setup])->save();

    $this->actingAs($owner)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('onboarding.items.0.href', route('profile.edit'))
        ->where('onboarding.items.1.href', route('properties.edit', $property))
        ->where('onboarding.items.2.href', route('rooms.index'))
        ->where('onboarding.items.3.href', route('rate-plans.index'))
        ->where('onboarding.items.4.href', route('tax-rules.index'))
        ->where('onboarding.items.5.href', route('invitations.create'))
        ->where('onboarding.items.7.href', route('reservations.create')));
});

test('missing registration gates link to the exact editable field only for authorized staff', function (OrganizationRole $role, bool $canEdit) {
    $t = twoTenants();
    $property = $t->a->property;
    $property->forceFill([
        'status' => PropertyStatus::Setup,
        'tin' => null,
        'z_number' => null,
        'vrn' => null,
        'zra_hotel_code' => null,
        'star_rating' => null,
    ])->save();

    $this->actingAs($t->a->member($role))->put(route('current-property.update'), ['property_id' => $property->id])->assertRedirect();
    $this->get(route('dashboard'))
        ->assertInertia(function (Assert $page) use ($property, $canEdit) {
            foreach ([4 => 'tin', 5 => 'z_number', 6 => 'zra_hotel_code', 7 => 'star_rating', 9 => 'vrn'] as $index => $field) {
                $page->where("onboarding.gates.{$index}.passed", false)
                    ->where("onboarding.gates.{$index}.href", $canEdit ? route('properties.edit', $property).'#'.$field : null);
            }
            foreach ([0, 1, 2, 3, 8] as $index) {
                $page->where("onboarding.gates.{$index}.href", null);
            }
        });
})->with([
    [OrganizationRole::Owner, true],
    [OrganizationRole::PropertyManager, true],
    [OrganizationRole::FrontDesk, false],
]);

test('an empty profile and another property data cannot complete the selected property checklist', function () {
    $t = twoTenants();
    $property = $t->a->property;
    $property->forceFill(['status' => PropertyStatus::Setup, 'phone' => ''])->save();
    $this->actingAs($t->a->owner)
        ->put(route('current-property.update'), ['property_id' => $property->id])
        ->assertRedirect(route('dashboard'));

    foreach ([$t->a->otherProperty, $t->b->property] as $other) {
        $other->forceFill(['status' => PropertyStatus::Setup])->save();
        inTenant($other, function () use ($other, $t) {
            $details = BookingForm::details($t->a->owner);
            app(CreateReservation::class)->handle($t->a->owner, $details);
            app(MarkTaxesReviewed::class)->handle($t->a->owner, $other);
            app(SendInvitation::class)->handle($t->a->owner, new InvitationDetails(
                'staff@example.test',
                MembershipGrant::properties(OrganizationRole::FrontDesk, [$other->id]),
                CarbonImmutable::now()->addWeek(),
            ));
        });
    }

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('onboarding.propertyId', $property->id)
        ->where('onboarding.items.1.completed', false)
        ->where('onboarding.items.2.completed', false)
        ->where('onboarding.items.2.items.0.completed', false)
        ->where('onboarding.items.2.items.1.completed', false)
        ->where('onboarding.items.3.completed', false)
        ->where('onboarding.items.4.completed', false)
        ->where('onboarding.items.5.completed', false)
        ->where('onboarding.items.7.completed', false)
        ->where('onboarding.canGoLive', false));
});

test('an unauthorized member sees progress but is not offered setup mutations', function () {
    $t = twoTenants();
    $property = $t->a->property;
    $property->forceFill(['status' => PropertyStatus::Setup])->save();
    inTenant($property, function () use ($property, $t) {
        BookingForm::details($t->a->owner);
        app(MarkTaxesReviewed::class)->handle($t->a->owner, $property);
    });

    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('onboarding.gates.3.passed', true)
            ->where('onboarding.items.2.items.0.completed', true)
            ->where('onboarding.items.2.items.0.href', null)
            ->where('onboarding.items.2.items.1.completed', true)
            ->where('onboarding.items.2.items.1.href', null)
            ->where('onboarding.canGoLive', false)
            ->where('onboarding.canReviewTaxes', false));
});

test('only a test created event counts as making a sample reservation', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, fn () => app(CreateReservation::class)->handle($owner, BookingForm::details($owner)));
    $property->forceFill(['status' => PropertyStatus::Setup])->save();

    $this->actingAs($owner)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('onboarding.items.7.completed', false));

    inTenant($property, fn () => ReservationLifecycleEvent::query()->update(['test' => true]));
    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('onboarding.items.7.completed', true));
});
