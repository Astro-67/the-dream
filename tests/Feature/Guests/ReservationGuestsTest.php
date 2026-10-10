<?php

use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;

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
