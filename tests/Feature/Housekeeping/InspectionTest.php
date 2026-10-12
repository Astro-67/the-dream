<?php

use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PropertyForm;

test('property managers can enable inspection and it defaults off', function () {
    $t = twoTenants();
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager));
    $this->get(route('properties.edit', $t->a->property))->assertInertia(fn (Assert $page) => $page->where('property.housekeeping_inspection_required', false));
    $this->put(route('properties.update', $t->a->property), PropertyForm::payload([
        '_property' => $t->a->property->id, 'housekeeping_inspection_required' => true,
    ]))->assertRedirect()->assertSessionHasNoErrors();
    $this->get(route('properties.edit', $t->a->property))->assertInertia(fn (Assert $page) => $page->where('property.housekeeping_inspection_required', true));
});
