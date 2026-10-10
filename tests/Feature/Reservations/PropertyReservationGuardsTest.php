<?php

use App\Domains\Reservations\Actions\CreateReservation;
use Tests\Support\BookingForm;

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
