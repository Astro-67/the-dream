<?php

use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\Booker;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;

test('front desk records and edits a guest at the current property', function () {
    $t = twoTenants();
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));
    $details = ['_property' => $t->a->property->id, 'name' => 'Amina Said', 'email' => 'amina@example.test', 'phone' => '+255700123456', 'nationality' => 'TZ', 'preferences' => 'Quiet room', 'notes' => 'Returning guest'];
    $this->get(route('guests.create'))->assertOk();
    $this->post(route('guests.store'), $details)->assertSessionHasNoErrors()->assertRedirect();
    $guest = inTenant($t->a->property, fn () => Guest::query()->sole());
    $this->get(route('guests.index'))->assertInertia(fn (Assert $page) => $page->component('guests/index')->where('records.data.0.name', 'Amina Said')->where('records.total', 1));
    $this->get(route('guests.edit', $guest))->assertInertia(fn (Assert $page) => $page->component('guests/edit')->where('record.preferences', 'Quiet room'));
    $this->put(route('guests.update', $guest), [...$details, 'name' => 'Amina Omar'])->assertSessionHasNoErrors()->assertRedirect();
    $this->get(route('guests.show', $guest))->assertInertia(fn (Assert $page) => $page->component('guests/show')->where('record.name', 'Amina Omar')->where('record.notes', 'Returning guest'));
});

test('front desk records a booker separately from guests', function () {
    $t = twoTenants();
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));
    $details = ['_property' => $t->a->property->id, 'name' => 'Travel Coordinator', 'email' => 'travel@example.test', 'nationality' => 'TZ', 'preferences' => 'Email contact', 'notes' => 'Books for others'];
    $this->get(route('bookers.create'))->assertOk();
    $this->post(route('bookers.store'), $details)->assertSessionHasNoErrors()->assertRedirect();
    $booker = inTenant($t->a->property, fn () => Booker::query()->sole());
    $this->get(route('bookers.index'))->assertInertia(fn (Assert $page) => $page->component('bookers/index')->where('records.data.0.name', 'Travel Coordinator'));
    $this->get(route('bookers.edit', $booker))->assertOk();
    $this->put(route('bookers.update', $booker), [...$details, 'name' => 'New Coordinator'])->assertSessionHasNoErrors();
    $this->get(route('bookers.show', $booker))->assertInertia(fn (Assert $page) => $page->where('record.name', 'New Coordinator')->where('record.notes', 'Books for others'));
    $this->get(route('guests.index'))->assertInertia(fn (Assert $page) => $page->where('records.total', 0));
});
