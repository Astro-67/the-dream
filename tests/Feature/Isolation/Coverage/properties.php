<?php

use App\Domains\Tenancy\Actions\ArchiveProperty;
use App\Domains\Tenancy\Models\Property;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\PropertyForm;
use Tests\Support\TwoTenants;

/*
 * Isolation coverage for routes/web/properties.php. See README.md.
 */

/**
 * Bravo's properties as stored, to prove an attack changed nothing.
 *
 * @return array<string, array<string, mixed>>
 */
$bravoProperties = fn (TwoTenants $t): array => inTenant($t->b->organization, fn () => Property::query()
    ->orderBy('id')->get()->keyBy('id')->map(fn (Property $p) => [...$p->only(['name', 'email', 'currency', 'timezone']), 'archived_at' => $p->archived_at?->toIso8601String()])->all());

return [
    // Switching to Bravo's property is refused, and the member stays in Alpha.
    'current-property.update' => RouteCoverage::attempt(fn (TwoTenants $t) => [
        $this->actingAs($t->a->owner)
            ->put(route('current-property.update'), ['property_id' => $t->b->property->id])
            ->assertForbidden(),
        $this->actingAs($t->a->owner)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('tenant.organization.id', $t->a->organization->id)),
    ]),

    'properties.index' => RouteCoverage::visit(),

    'properties.create' => RouteCoverage::visit(),

    // A form carrying Bravo's property is refused; a new property always lands in Alpha.
    'properties.store' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoProperties) {
        $before = $bravoProperties($t);

        $responses = [
            $this->actingAs($t->a->owner)
                ->post(route('properties.store'), PropertyForm::payload(['_property' => $t->b->property->id]))
                ->assertForbidden(),
            $this->actingAs($t->a->owner)
                ->post(route('properties.store'), PropertyForm::payload(['_property' => $t->a->property->id]))
                ->assertRedirect(route('properties.index')),
        ];

        expect($bravoProperties($t))->toBe($before);

        return $responses;
    }),

    'properties.edit' => RouteCoverage::attempt(fn (TwoTenants $t) => $this->actingAs($t->a->owner)
        ->get(route('properties.edit', $t->b->property->id))
        ->assertNotFound()),

    'properties.update' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoProperties) {
        $before = $bravoProperties($t);
        $payload = PropertyForm::payload(['name' => 'Hijacked by Alpha']);

        $responses = [
            $this->actingAs($t->a->owner)
                ->put(route('properties.update', $t->b->property->id), [...$payload, '_property' => $t->a->property->id])
                ->assertNotFound(),
            $this->actingAs($t->a->owner)
                ->put(route('properties.update', $t->b->property->id), [...$payload, '_property' => $t->b->property->id])
                ->assertForbidden(),
        ];

        expect($bravoProperties($t))->toBe($before);

        return $responses;
    }),

    // Archiving Bravo's property: not found through Alpha, refused when the form names Bravo's property.
    'properties.archive' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoProperties) {
        $before = $bravoProperties($t);

        $responses = [
            $this->actingAs($t->a->owner)
                ->post(route('properties.archive', $t->b->otherProperty->id), ['_property' => $t->a->property->id])
                ->assertNotFound(),
            $this->actingAs($t->a->owner)
                ->post(route('properties.archive', $t->b->otherProperty->id), ['_property' => $t->b->property->id])
                ->assertForbidden(),
        ];

        expect($bravoProperties($t))->toBe($before);

        return $responses;
    }),

    // Restoring Bravo's archived property: the same two refusals, and it stays archived.
    'properties.restore' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoProperties) {
        inTenant($t->b->property, fn () => app(ArchiveProperty::class)->handle($t->b->owner, $t->b->otherProperty));
        $before = $bravoProperties($t);

        $responses = [
            $this->actingAs($t->a->owner)
                ->delete(route('properties.restore', $t->b->otherProperty->id), ['_property' => $t->a->property->id])
                ->assertNotFound(),
            $this->actingAs($t->a->owner)
                ->delete(route('properties.restore', $t->b->otherProperty->id), ['_property' => $t->b->property->id])
                ->assertForbidden(),
        ];

        expect($bravoProperties($t))->toBe($before)
            ->and($before[$t->b->otherProperty->id]['archived_at'])->not->toBeNull();

        return $responses;
    }),
];
