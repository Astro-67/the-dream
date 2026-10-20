<?php

use App\Domains\Reservations\Models\Reservation;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;

test('inline lead guest reserves without a booker and records inclusions', function () {
    $t = twoTenants();
    $details = inTenant($t->a->property, fn () => BookingForm::details($t->a->owner));
    unset($details['booker_id']);
    $response = $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)->post(route('reservations.store'), [
        ...$details, '_property' => $t->a->property->id,
        'lead_guest' => ['first_name' => 'Asha', 'last_name' => 'Ali', 'nationality' => 'TZ'],
        'meal_plan' => 'half-board', 'source' => 'whatsapp', 'special_requests' => 'Quiet room', 'nightly_rate' => 9500,
        'booked_through' => 'tour-operator', 'tour_operator_name' => 'Island Tours', 'tour_operator_registration' => 'ZRA-123', 'tour_operator_pays_for' => 'room-and-levy',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->get($response->headers->get('Location'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('booker', null)->where('leadGuest.name', 'Asha Ali')->where('reservation.meal_plan', 'half-board')
        ->where('reservation.source', 'whatsapp')->where('reservation.special_requests', 'Quiet room')
        ->where('reservation.tour_operator_registration', 'ZRA-123')->where('reservation.price_lines.0.amount', 9500));
    inTenant($t->a->property, fn () => expect(Reservation::query()->sole()->getAttribute('booker_id'))->toBeNull());
});

test('tour operator requires registration', function () {
    $t = twoTenants();
    $details = inTenant($t->a->property, fn () => BookingForm::details($t->a->owner));
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)->postJson(route('reservations.store'), [
        ...$details, '_property' => $t->a->property->id, 'booked_through' => 'tour-operator', 'tour_operator_name' => 'Tours', 'tour_operator_pays_for' => 'room-only',
    ])->assertUnprocessable()->assertJsonValidationErrors('tour_operator_registration');
});

test('walk-in rules follow property settings', function () {
    $t = twoTenants();
    $details = inTenant($t->a->property, fn () => BookingForm::details($t->a->owner));
    $t->a->property->update(['business_date' => '2026-12-10', 'front_desk_settings' => ['walk_ins_enabled' => false]]);
    $payload = [...$details, '_property' => $t->a->property->id, 'source' => 'walk-in', 'lead_guest' => ['first_name' => 'Asha', 'nationality' => 'TZ']];
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)->postJson(route('reservations.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('source');
    $t->a->property->update(['front_desk_settings' => ['walk_ins_enabled' => true, 'walk_in_requires_id' => true]]);
    $this->postJson(route('reservations.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('lead_guest.document_type');
    $payload['lead_guest'] += ['document_type' => 'nida', 'document_number' => '12345'];
    $this->postJson(route('reservations.store'), [...$payload, 'arrival' => '2026-12-11'])->assertUnprocessable()->assertJsonValidationErrors('arrival');
    $this->postJson(route('reservations.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
});

test('foreign guest document type follows passport setting', function () {
    $t = twoTenants();
    $details = inTenant($t->a->property, fn () => BookingForm::details($t->a->owner));
    $payload = [...$details, '_property' => $t->a->property->id, 'lead_guest' => ['first_name' => 'Sam', 'last_name' => 'Lee', 'nationality' => 'GB', 'document_type' => 'nida', 'document_number' => '12345']];
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)->postJson(route('reservations.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('lead_guest.document_type');
    $t->a->property->update(['front_desk_settings' => ['passport_required_for_foreign_guests' => false]]);
    $this->postJson(route('reservations.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
});
