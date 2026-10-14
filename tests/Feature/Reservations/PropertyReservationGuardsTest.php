<?php

use App\Domains\Reservations\Actions\CreateReservation;
use Carbon\CarbonImmutable;
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

test('reservation guards ignore cancelled void and elapsed reservations', function (string $status, string $localDate) {
    $this->travelTo(CarbonImmutable::parse('2026-12-09 12:00', 'Africa/Dar_es_Salaam'));
    $t = twoTenants();
    inTenant($t->a->property, function () use ($t, $status) {
        $reservation = app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
        $reservation->update(['status' => $status]);
    });
    $t->a->property->update(['business_date' => $localDate]);

    $this->actingAs($t->a->owner)
        ->put(route('properties.update', $t->a->property), PropertyForm::payload(['_property' => $t->a->property->id]))
        ->assertSessionHasNoErrors();
    expect(inTenant($t->a->organization, fn () => $t->a->property->fresh()->jurisdiction_profile->value))->toBe('mainland_tanzania');
    $this->post(route('properties.archive', $t->a->property), ['_property' => $t->a->property->id])->assertSessionHasNoErrors();
    expect(inTenant($t->a->organization, fn () => $t->a->property->fresh()->archived_at))->not->toBeNull();
})->with([
    'cancelled future reservation' => ['cancelled', '2026-12-09'],
    'void future reservation' => ['void', '2026-12-09'],
    'departure today' => ['confirmed', '2026-12-12'],
    'past departure' => ['confirmed', '2026-12-13'],
]);

test('future reservations in another property or organization do not block property changes', function () {
    $this->travelTo(now()->setDate(2026, 12, 9));
    $t = twoTenants();
    foreach ([$t->a, $t->b] as $tenant) {
        inTenant($tenant->otherProperty, fn () => app(CreateReservation::class)->handle($tenant->owner, BookingForm::details($tenant->owner)));
    }

    $this->actingAs($t->a->owner)
        ->put(route('properties.update', $t->a->property), PropertyForm::payload(['_property' => $t->a->property->id]))
        ->assertSessionHasNoErrors();
    $this->post(route('properties.archive', $t->a->property), ['_property' => $t->a->property->id])->assertSessionHasNoErrors();
    expect(inTenant($t->a->organization, fn () => $t->a->property->fresh()->archived_at))->not->toBeNull();
});

test('an unchanged profile permits other edits while confirmed reservations remain', function () {
    $this->travelTo(now()->setDate(2026, 12, 9));
    $t = twoTenants();
    inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));

    $this->actingAs($t->a->owner)
        ->put(route('properties.update', $t->a->property), PropertyForm::payload([
            '_property' => $t->a->property->id, 'jurisdiction_profile' => 'zanzibar', 'zra_hotel_code' => 'ZH-ALPHA',
        ]))->assertSessionHasNoErrors();
    expect(inTenant($t->a->organization, fn () => $t->a->property->fresh()->name))->toBe('Serengeti Gate Lodge');
});

test('reservations with nights remaining block both guards after arrival using the property local date', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-09 12:00', 'UTC'));
    $t = twoTenants();
    inTenant($t->a->property, function () use ($t) {
        $t->a->property->update(['timezone' => 'America/New_York']);
        app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
    });
    $this->travelTo(CarbonImmutable::parse('2026-12-12 01:30', 'UTC'));

    $this->actingAs($t->a->owner)
        ->put(route('properties.update', $t->a->property), PropertyForm::payload(['_property' => $t->a->property->id]))
        ->assertSessionHasErrors('jurisdiction_profile');
    $this->post(route('properties.archive', $t->a->property), ['_property' => $t->a->property->id])->assertSessionHasErrors('archive');
});
