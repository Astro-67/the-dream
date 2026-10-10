<?php

use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Guests\Models\Guest;
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
