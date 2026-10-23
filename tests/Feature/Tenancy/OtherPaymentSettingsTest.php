<?php

use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\Property;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PropertyForm;

test('new properties accept Other by default', function () {
    [$owner, , $first] = organizationWithOwner();
    $this->actingAs($owner)->post(route('properties.store'), PropertyForm::payload(['_property' => $first->id]))
        ->assertRedirect()->assertSessionHasNoErrors();
    $property = inTenant($first, fn () => Property::query()->where('name', 'Serengeti Gate Lodge')->sole());

    $this->get(route('properties.edit', $property))->assertInertia(fn (Assert $page) => $page
        ->where('property.payment_settings.accepted_methods', ['cash', 'bank_transfer', 'mobile_money', 'card', 'channel_collected', 'other']));
});

test('saved accepted methods stay unchanged until a property manager enables Other', function () {
    $t = twoTenants();
    $saved = ['cash', 'bank_transfer', 'mobile_money', 'card', 'channel_collected'];
    $t->a->property->update(['payment_settings' => ['accepted_methods' => $saved]]);
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager));

    $this->get(route('properties.edit', $t->a->property))->assertInertia(fn (Assert $page) => $page
        ->where('property.payment_settings.accepted_methods', $saved));
    $this->put(route('properties.update', $t->a->property), PropertyForm::payload([
        '_property' => $t->a->property->id, 'payment_settings' => ['deposit_percentage' => 25],
    ]))->assertSessionHasNoErrors();
    expect($t->a->property->fresh()->payment_settings['accepted_methods'])->toEqual($saved);

    foreach ([[...$saved, 'other'], $saved] as $methods) {
        $this->put(route('properties.update', $t->a->property), PropertyForm::payload([
            '_property' => $t->a->property->id, 'payment_settings' => ['accepted_methods' => $methods],
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('properties.edit', $t->a->property))->assertInertia(fn (Assert $page) => $page
            ->where('property.payment_settings.accepted_methods', $methods)
            ->where('property.payment_settings.deposit_percentage', 25));
    }
});
