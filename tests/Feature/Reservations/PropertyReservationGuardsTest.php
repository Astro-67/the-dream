<?php

use App\Domains\Reservations\Actions\CreateReservation;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;
use Tests\Support\PropertyForm;

test('future confirmed reservations refuse archival with a clear reason', function () {
    $this->travelTo(now()->setDate(2026, 12, 9));
    $t = twoTenants();
    inTenant($t->a->otherProperty, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));

    $this->actingAs($t->a->owner)
        ->from(route('properties.index'))
        ->post(route('properties.archive', $t->a->otherProperty), ['_property' => $t->a->property->id])
        ->assertRedirect(route('properties.index'))
        ->assertSessionHasErrors(['archive' => 'This property has future confirmed reservations. Cancel or complete them before archiving the property.']);

    expect(inTenant($t->a->organization, fn () => $t->a->otherProperty->fresh()->archived_at))->toBeNull();
});

test('future confirmed reservations refuse profile changes without saving other details and audit the refusal', function () {
    $this->travelTo(now()->setDate(2026, 12, 9));
    $t = twoTenants();
    inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));

    $this->actingAs($t->a->owner)
        ->from(route('properties.edit', $t->a->property))
        ->put(route('properties.update', $t->a->property), PropertyForm::payload(['_property' => $t->a->property->id]))
        ->assertRedirect(route('properties.edit', $t->a->property))
        ->assertSessionHasErrors(['jurisdiction_profile' => 'This property has future confirmed reservations. Cancel or complete them before changing its jurisdiction profile.']);

    $property = inTenant($t->a->organization, fn () => $t->a->property->fresh());
    expect($property->jurisdiction_profile->value)->toBe('zanzibar')
        ->and($property->name)->toBe('Alpha Stone Town Inn');
    $refusal = Activity::query()->where('subject_id', $property->id)->where('event', 'refused')->sole();
    expect($refusal->getExtraProperty('result'))->toBe('refused')
        ->and($refusal->causer->is($t->a->owner))->toBeTrue();
});
