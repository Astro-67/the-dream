<?php

use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\JurisdictionProfile;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;
use Tests\Support\TenantFixture;

const ADD_GUEST_PASSPORT = ['type' => 'passport', 'number' => 'P1234567', 'nationality' => 'DE'];

function addGuestStay(TenantFixture $tenant, ReservationStatus $status = ReservationStatus::CheckedIn): Reservation
{
    return inTenant($tenant->property, function () use ($tenant, $status) {
        $reservation = app(CreateReservation::class)->handle($tenant->owner, BookingForm::details($tenant->owner));
        $reservation->update(['status' => $status]);

        return $reservation;
    });
}

/** @return array{guests: int, events: int} */
function addGuestCounts(TenantFixture $tenant, Reservation $reservation): array
{
    return inTenant($tenant->property, fn () => [
        'guests' => ReservationGuest::query()->where('reservation_id', $reservation->id)->count(),
        'events' => $reservation->lifecycleEvents()->where('type', 'guest_added')->count(),
    ]);
}

test('front desk adds a registered guest in house, recording one guest_added event', function () {
    $t = twoTenants();
    $reservation = addGuestStay($t->a);
    $actor = $t->a->member(OrganizationRole::FrontDesk);
    $this->actingAs($actor)->post(route('reservations.add-guest', $reservation), ['_property' => $t->a->property->id, 'name' => 'Late Arrival', 'documents' => [ADD_GUEST_PASSPORT]])
        ->assertSessionHasNoErrors()->assertRedirect(route('reservations.guests.create', $reservation));
    expect(addGuestCounts($t->a, $reservation))->toBe(['guests' => 1, 'events' => 1]);
    inTenant($t->a->property, function () use ($reservation, $actor) {
        $event = $reservation->lifecycleEvents()->where('type', 'guest_added')->sole();
        expect($event->actor_id)->toBe($actor->id)->and($event->snapshot['guest_id'])->toBe(Guest::query()->sole()->id);
    });
});

/**
 * Posts add-guest and asserts it was refused on $field with nothing attached or recorded.
 *
 * @param  array<string, mixed>  $payload
 */
function assertAddGuestRefused(TenantFixture $tenant, Reservation $reservation, array $payload, string $field): void
{
    $before = addGuestCounts($tenant, $reservation);
    test()->actingAs($tenant->owner)->postJson(route('reservations.add-guest', $reservation), ['_property' => $tenant->property->id, ...$payload])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(addGuestCounts($tenant, $reservation))->toBe($before);
}

test('at a Zanzibar property adding a guest is refused while any identity field is missing', function (array $documents) {
    $t = twoTenants();
    $reservation = addGuestStay($t->a);
    assertAddGuestRefused($t->a, $reservation, ['name' => 'Unregistered', 'documents' => $documents], 'guests');
    expect(inTenant($t->a->property, fn () => Guest::query()->count()))->toBe(0);
})->with([
    'no document' => [[]],
    'no nationality' => [[['type' => 'passport', 'number' => 'P7654321']]],
]);

test('a matched guest without a complete identity document is refused', function () {
    $t = twoTenants();
    $reservation = addGuestStay($t->a);
    $guest = inTenant($t->a->property, fn () => app(CreateGuest::class)->handle($t->a->owner, ['name' => 'Returning Guest', 'documents' => [['type' => 'passport', 'number' => 'P7654321']]]));
    assertAddGuestRefused($t->a, $reservation, ['guest_id' => $guest->id], 'guests');
});

test('staff link a matched guest only by choosing it; matching details alone create a new guest', function () {
    $t = twoTenants();
    $reservation = addGuestStay($t->a);
    $returning = inTenant($t->a->property, fn () => app(CreateGuest::class)->handle($t->a->owner, ['name' => 'Amina Said', 'email' => 'amina@example.test', 'documents' => [ADD_GUEST_PASSPORT]]));
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));
    $this->postJson(route('guests.matches'), ['_property' => $t->a->property->id, 'name' => 'Amina Said', 'email' => 'amina@example.test'])->assertJsonPath('matches.0.id', $returning->id);
    $this->post(route('reservations.add-guest', $reservation), ['_property' => $t->a->property->id, 'name' => 'Amina Said', 'email' => 'amina@example.test', 'documents' => [ADD_GUEST_PASSPORT]])->assertSessionHasNoErrors();
    expect(inTenant($t->a->property, fn () => ReservationGuest::query()->sole()->guest_id))->not->toBe($returning->id);
    $this->post(route('reservations.add-guest', $reservation), ['_property' => $t->a->property->id, 'guest_id' => $returning->id])->assertSessionHasNoErrors();
    expect(addGuestCounts($t->a, $reservation))->toBe(['guests' => 2, 'events' => 2]);
});

test('a guest already staying is not added twice', function () {
    $t = twoTenants();
    $reservation = addGuestStay($t->a);
    $guest = inTenant($t->a->property, fn () => app(CreateGuest::class)->handle($t->a->owner, ['name' => 'Staying', 'documents' => [ADD_GUEST_PASSPORT]]));
    $this->actingAs($t->a->owner)->post(route('reservations.add-guest', $reservation), ['_property' => $t->a->property->id, 'guest_id' => $guest->id])->assertSessionHasNoErrors();
    assertAddGuestRefused($t->a, $reservation, ['guest_id' => $guest->id], 'guest_id');
});

test('at a mainland property only the configured registration fields are enforced', function () {
    $t = twoTenants();
    $t->a->property->update(['jurisdiction_profile' => JurisdictionProfile::MainlandTanzania, 'zra_hotel_code' => null, 'registration_fields' => null]);
    $reservation = addGuestStay($t->a);
    $this->actingAs($t->a->owner)->post(route('reservations.add-guest', $reservation), ['_property' => $t->a->property->id, 'name' => 'No Documents'])->assertSessionHasNoErrors();
    $t->a->property->update(['registration_fields' => ['number']]);
    assertAddGuestRefused($t->a, $reservation, ['name' => 'Still None'], 'guests');
    expect(addGuestCounts($t->a, $reservation))->toBe(['guests' => 1, 'events' => 1]);
});

test('adding a guest is refused unless the reservation is checked in', function (ReservationStatus $status) {
    $t = twoTenants();
    $reservation = addGuestStay($t->a, $status);
    assertAddGuestRefused($t->a, $reservation, ['name' => 'Too Early', 'documents' => [ADD_GUEST_PASSPORT]], 'reservation');
})->with(array_filter(ReservationStatus::cases(), fn ($status) => $status !== ReservationStatus::CheckedIn));

test('only staff who manage reservations and register guests may add a guest', function (OrganizationRole $role, bool $allowed) {
    $t = twoTenants();
    $reservation = addGuestStay($t->a);
    $guest = inTenant($t->a->property, fn () => app(CreateGuest::class)->handle($t->a->owner, ['name' => 'Late Arrival', 'documents' => [ADD_GUEST_PASSPORT]]));
    $response = $this->actingAs($t->a->member($role))->post(route('reservations.add-guest', $reservation), ['_property' => $t->a->property->id, 'guest_id' => $guest->id]);
    $allowed ? $response->assertRedirect()->assertSessionHasNoErrors() : $response->assertForbidden();
    expect(addGuestCounts($t->a, $reservation))->toBe($allowed ? ['guests' => 1, 'events' => 1] : ['guests' => 0, 'events' => 0]);
})->with(array_map(fn ($role) => [$role, in_array($role, [OrganizationRole::Owner, OrganizationRole::GroupOperationsManager, OrganizationRole::PropertyManager, OrganizationRole::FrontDesk], true)], OrganizationRole::cases()));

test('in house, the guests page adds through registration and plain attaching is refused', function () {
    $t = twoTenants();
    $reservation = addGuestStay($t->a);
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));
    $this->get(route('reservations.guests.create', $reservation))->assertInertia(fn (AssertableInertia $page) => $page->where('inHouse', true)->where('canAttach', true));
    $this->postJson(route('reservations.guests.store', $reservation), ['_property' => $t->a->property->id, 'name' => 'Unregistered'])->assertUnprocessable()->assertJsonValidationErrors('reservation');
    expect(addGuestCounts($t->a, $reservation))->toBe(['guests' => 0, 'events' => 0]);
    expect(Activity::query()->where('subject_id', $reservation->id)->where('description', 'Guest attachment refused')->where('event', 'refused')->exists())->toBeTrue();
});

test('adding a guest requires the opening property intent', function () {
    $t = twoTenants();
    $reservation = addGuestStay($t->a);
    $this->actingAs($t->a->owner)->postJson(route('reservations.add-guest', $reservation), ['name' => 'Late Arrival', 'documents' => [ADD_GUEST_PASSPORT]])->assertUnprocessable()->assertJsonValidationErrors('_property');
    expect(addGuestCounts($t->a, $reservation))->toBe(['guests' => 0, 'events' => 0]);
});
