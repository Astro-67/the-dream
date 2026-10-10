<?php

use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\Guest;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;

test('staff explicitly links or creates a staying guest without attaching the booker automatically', function () {
    $t = twoTenants();
    $reservation = inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    $guest = inTenant($t->a->property, fn () => app(CreateGuest::class)->handle($t->a->owner, ['name' => 'Amina Said', 'email' => 'amina@example.test']));
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));
    $this->get(route('reservations.guests.create', $reservation))->assertInertia(fn (Assert $page) => $page->component('reservations/guests')->has('guests', 0));
    $this->post(route('reservations.guests.store', $reservation), ['_property' => $t->a->property->id, 'guest_id' => $guest->id])->assertSessionHasNoErrors()->assertRedirect();
    $this->post(route('reservations.guests.store', $reservation), ['_property' => $t->a->property->id, 'name' => 'Amina Said', 'email' => 'amina@example.test'])->assertSessionHasNoErrors()->assertRedirect();
    $this->get(route('reservations.guests.create', $reservation))->assertInertia(fn (Assert $page) => $page->has('guests', 2)->where('guests.0.name', 'Amina Said')->where('guests.1.name', 'Amina Said'));
    expect(inTenant($t->a->property, fn () => Guest::query()->count()))->toBe(2);
});

test('phone and encrypted document matches never disclose identity fields', function () {
    $t = twoTenants();
    $guest = inTenant($t->a->property, fn () => app(CreateGuest::class)->handle($t->a->owner, ['name' => 'Amina Said', 'phone' => '+255700123456', 'documents' => [['type' => 'passport', 'number' => 'SECRET-123']]]));
    $this->actingAs($t->a->member(OrganizationRole::Finance));
    foreach (['phone' => '+255700123456', 'document_number' => 'SECRET-123'] as $field => $value) {
        $response = $this->postJson(route('guests.matches'), ['_property' => $t->a->property->id, 'name' => 'Amina Said', $field => $value]);
        $response->assertOk()->assertJsonPath('matches.0.id', $guest->id)->assertDontSee('SECRET-123')->assertDontSee('passport')->assertJsonMissingPath('matches.0.documents');
    }
    $this->postJson(route('guests.matches'), ['_property' => $t->a->property->id, 'name' => 'Amina Said'])->assertOk()->assertJsonCount(0, 'matches');
    $this->postJson(route('guests.matches'), ['_property' => $t->a->property->id, 'name' => 'Another Person', 'document_number' => 'SECRET-123'])->assertOk()->assertJsonCount(0, 'matches');
});

test('attachments reject another property guest and reservation and are idempotent', function () {
    $t = twoTenants();
    $reservation = inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    $guest = inTenant($t->a->property, fn () => app(CreateGuest::class)->handle($t->a->owner, ['name' => 'Staying Guest']));
    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id]);
    foreach ([$t->a->otherProperty, $t->b->property] as $property) {
        $foreignGuest = inTenant($property, fn () => app(CreateGuest::class)->handle($t->a->owner, ['name' => 'Foreign Guest']));
        $foreignReservation = inTenant($property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
        $this->postJson(route('reservations.guests.store', $reservation), ['_property' => $t->a->property->id, 'guest_id' => $foreignGuest->id])->assertUnprocessable()->assertJsonValidationErrors('guest_id');
        $this->postJson(route('reservations.guests.store', $foreignReservation), ['_property' => $t->a->property->id, 'guest_id' => $guest->id])->assertNotFound();
    }
    for ($i = 0; $i < 2; $i++) {
        $this->post(route('reservations.guests.store', $reservation), ['_property' => $t->a->property->id, 'guest_id' => $guest->id])->assertSessionHasNoErrors();
    }
    $this->get(route('reservations.guests.create', $reservation))->assertOk()->assertInertia(fn (Assert $page) => $page->has('guests', 1));
});

test('only staff with reservation management and guest registration can attach guests', function (OrganizationRole $role, bool $allowed) {
    $t = twoTenants();
    $reservation = inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    $response = $this->actingAs($t->a->member($role))->post(route('reservations.guests.store', $reservation), ['_property' => $t->a->property->id, 'name' => 'Staying Guest']);
    $allowed ? $response->assertRedirect()->assertSessionHasNoErrors() : $response->assertForbidden();
})->with([
    [OrganizationRole::FrontDesk, true], [OrganizationRole::PropertyManager, true], [OrganizationRole::GroupOperationsManager, true],
    [OrganizationRole::Finance, false], [OrganizationRole::Auditor, false], [OrganizationRole::Housekeeping, false],
]);

test('matching offers only same-property guests with name and a contact match', function () {
    $t = twoTenants();
    $guest = inTenant($t->a->property, fn () => app(CreateGuest::class)->handle($t->a->owner, ['name' => 'Amina Said', 'email' => 'amina@example.test']));
    foreach ([$t->a->otherProperty, $t->b->property] as $property) {
        inTenant($property, fn () => app(CreateGuest::class)->handle($t->a->owner, ['name' => 'Amina Said', 'email' => 'amina@example.test']));
    }
    inTenant($t->a->property, fn () => app(CreateGuest::class)->handle($t->a->owner, ['name' => 'Someone Else', 'email' => 'amina@example.test']));
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))
        ->postJson(route('guests.matches'), ['_property' => $t->a->property->id, 'name' => ' amina said ', 'email' => 'AMINA@example.test'])
        ->assertOk()->assertJsonCount(1, 'matches')->assertJsonPath('matches.0.id', $guest->id);
});
