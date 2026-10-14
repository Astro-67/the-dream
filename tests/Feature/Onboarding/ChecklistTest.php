<?php

use App\Domains\Reservations\Actions\CreateReservation;
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

test('setup progress survives leaving and returning and offers go-live only after the gates pass', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->forceFill(['status' => PropertyStatus::Setup, 'business_date' => '2026-12-01'])->save();
    $this->actingAs($owner);

    $details = inTenant($property, fn () => BookingForm::details($owner));
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('onboarding.items.2.completed', true)
        ->where('onboarding.items.3.completed', true)
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
        ->where('onboarding.canGoLive', true));
    $this->get(route('properties.index'))->assertOk();
    $after = $this->get(route('dashboard'))->assertOk();

    expect($after->inertiaProps('onboarding'))->toEqual($before->inertiaProps('onboarding'));

    $this->post(route('properties.go-live', $property), ['_property' => $property->id])->assertSessionHasNoErrors();
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('onboarding', null));
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
        ->where('onboarding.items.3.href', route('rate-calendar'))
        ->where('onboarding.items.4.href', route('tax-rules.index'))
        ->where('onboarding.items.5.href', route('invitations.create'))
        ->where('onboarding.items.7.href', route('reservations.create')));
});
