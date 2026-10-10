<?php

use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;

test('a booking received outside integration requires a reason and reference and is flagged for reconciliation', function () {
    $t = twoTenants();
    $details = inTenant($t->a->property, fn () => BookingForm::details($t->a->owner));
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));
    $form = [...$details, '_property' => $t->a->property->id, 'source' => 'outside-integration'];
    $this->postJson(route('reservations.store'), $form)->assertUnprocessable()->assertJsonValidationErrors(['outside_integration_reason', 'channel_reference']);
    $response = $this->post(route('reservations.store'), [...$form, 'outside_integration_reason' => 'Channel email fallback during outage', 'channel_reference' => 'OTA-123']);
    $response->assertRedirect();
    $this->get($response->headers->get('Location'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('reservation.channel_reference', 'OTA-123')
        ->where('reservation.outside_integration_reason', 'Channel email fallback during outage')
        ->where('reservation.requires_reconciliation', true));
    $this->get(route('reservations.index', ['list' => 'all', 'search' => 'OTA-123']))->assertOk()->assertInertia(fn (Assert $page) => $page->has('reservations.data', 1)->where('reservations.data.0.channel_reference', 'OTA-123'));
});

test('ordinary staff bookings cannot inject reconciliation metadata', function () {
    $t = twoTenants();
    $details = inTenant($t->a->property, fn () => BookingForm::details($t->a->owner));
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));
    $response = $this->post(route('reservations.store'), [...$details, '_property' => $t->a->property->id,
        'requires_reconciliation' => true, 'channel_reference' => 'UNTRUSTED', 'outside_integration_reason' => 'UNTRUSTED']);
    $response->assertRedirect();
    $this->get($response->headers->get('Location'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('reservation.channel_reference', null)->where('reservation.outside_integration_reason', null)
        ->where('reservation.requires_reconciliation', false));
});

test('outside integration whitespace reasons and references are refused before taking inventory', function () {
    $t = twoTenants();
    $details = inTenant($t->a->property, fn () => BookingForm::details($t->a->owner));
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));
    $this->postJson(route('reservations.store'), [...$details, '_property' => $t->a->property->id,
        'source' => 'outside-integration', 'channel_reference' => '  ', 'outside_integration_reason' => '  '])
        ->assertUnprocessable()->assertJsonValidationErrors(['channel_reference', 'outside_integration_reason']);
    $response = $this->post(route('reservations.store'), [...$details, '_property' => $t->a->property->id]);
    $response->assertRedirect();
    $this->get($response->headers->get('Location'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('reservation.status', 'confirmed'));
});
