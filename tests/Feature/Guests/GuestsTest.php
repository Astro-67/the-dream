<?php

use App\Domains\Guests\Models\Guest;
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
