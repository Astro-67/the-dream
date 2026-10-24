<?php

use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\JurisdictionProfile;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam')));

/** @return array{Reservation, Reservation, Guest} */
function documentGuardArrivals(Property $property, User $owner): array
{
    return inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        $firstRoom = Room::query()->sole();
        $secondRoom = app(CreateRoom::class)->handle($owner, InventoryForm::room($firstRoom->getAttribute('room_type_id'), ['number' => '202']));
        $stays = [];
        foreach ([$firstRoom, $secondRoom] as $room) {
            $stay = app(CreateReservation::class)->handle($owner, [...$details, 'room_id' => $room->id]);
            $guest = app(CreateGuest::class)->handle($owner, ['name' => 'Guest '.$room->getAttribute('number'), 'documents' => [
                ['type' => 'passport', 'number' => $room->is($firstRoom) ? 'P1234567' : " p 123\t4567\n", 'nationality' => 'DE'],
            ]]);
            ReservationGuest::query()->create(['reservation_id' => $stay->id, 'guest_id' => $guest->id]);
            $stays[] = $stay;
        }
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);

        return [...$stays, $guest];
    });
}

/**
 * protects: one normalized document is in-house once per Zanzibar property, with a useful refusal.
 * fails_when: a second stay checks in, the wrong room is named, or refusal leaks identity/hash data.
 * no_claim: simultaneous database connections or later document corrections.
 */
test('check-in refuses a document already in-house and names the room without leaking its number', function () {
    $t = twoTenants();
    [$first, $second, $guest] = documentGuardArrivals($t->a->property, $t->a->owner);
    $this->actingAs($t->a->owner)->postJson(route('reservations.check-in', $first), ['_property' => $t->a->property->id])->assertSessionHasNoErrors();
    $response = $this->postJson(route('reservations.check-in', $second), ['_property' => $t->a->property->id])
        ->assertUnprocessable()->assertJsonValidationErrors('guests');
    expect($response->json('errors.guests.0'))->toContain('101');
    inTenant($t->a->property, function () use ($second, $guest, $response) {
        expect($second->fresh()->status)->toBe(ReservationStatus::Confirmed)
            ->and($second->lifecycleEvents()->where('type', 'checked_in')->exists())->toBeFalse();
        $hash = $guest->documents()->sole()->getAttribute('number_hash');
        expect($response->getContent())->not->toContain('P1234567')->not->toContain($hash);
        $audit = Activity::query()->where('subject_id', $second->id)->where('event', 'refused')->sole();
        expect($audit->toJson())->not->toContain('P1234567')->not->toContain($hash);
    });
});

/**
 * protects: adding either a new or matched guest cannot reuse another in-house document.
 * fails_when: attaching bypasses the guard, creates a stray guest/event, or omits the other room.
 * no_claim: modifying documents after a guest has already been attached.
 */
test('adding a guest refuses another in-house document and rolls back the addition', function (bool $matched) {
    $t = twoTenants();
    [$first, $second, $guest] = documentGuardArrivals($t->a->property, $t->a->owner);
    $details = inTenant($t->a->property, function () use ($first, $guest, $matched) {
        $guest->documents()->sole()->update(['number' => 'OTHER']);

        return $matched ? ['guest_id' => $first->leadGuest()->id] : [
            'name' => 'Late Arrival',
            'documents' => [['type' => 'national_id', 'number' => "p 123\t4567", 'nationality' => 'TZ']],
        ];
    });
    $this->actingAs($t->a->owner);
    foreach ([$first, $second] as $stay) {
        $this->postJson(route('reservations.check-in', $stay), ['_property' => $t->a->property->id])->assertSessionHasNoErrors();
    }
    $response = $this->postJson(route('reservations.add-guest', $second), ['_property' => $t->a->property->id, ...$details])
        ->assertUnprocessable()->assertJsonValidationErrors('guests');
    expect($response->json('errors.guests.0'))->toContain('101');
    inTenant($t->a->property, function () use ($second) {
        expect(Guest::query()->count())->toBe(2)
            ->and(ReservationGuest::query()->where('reservation_id', $second->id)->count())->toBe(1)
            ->and($second->lifecycleEvents()->where('type', 'guest_added')->exists())->toBeFalse()
            ->and(Activity::query()->where('subject_id', $second->id)->where('description', 'Add guest refused')->where('event', 'refused')->exists())->toBeTrue();
    });
})->with(['new guest' => false, 'matched guest' => true]);

/**
 * protects: checked-out documents can be reused and mainland properties remain unaffected.
 * fails_when: historical stays block arrival or the rule spreads beyond Zanzibar.
 * no_claim: whether a checkout was financially eligible.
 */
test('a document may check in after checkout or at a mainland property', function (bool $mainland) {
    $t = twoTenants();
    if ($mainland) {
        $t->a->property->update(['jurisdiction_profile' => JurisdictionProfile::MainlandTanzania]);
    }
    [$first, $second] = documentGuardArrivals($t->a->property, $t->a->owner);
    $this->actingAs($t->a->owner)->postJson(route('reservations.check-in', $first), ['_property' => $t->a->property->id])->assertSessionHasNoErrors();
    if (! $mainland) {
        inTenant($t->a->property, fn () => $first->update(['status' => ReservationStatus::CheckedOut]));
    }
    $this->postJson(route('reservations.check-in', $second), ['_property' => $t->a->property->id])->assertSessionHasNoErrors();
    inTenant($t->a->property, fn () => expect($second->fresh()->status)->toBe(ReservationStatus::CheckedIn));
})->with(['checked out' => false, 'mainland' => true]);

/**
 * protects: document collisions never cross property or organization boundaries.
 * fails_when: a Zanzibar property is blocked by another property's in-house document.
 * no_claim: cross-tenant route authorization, covered by the isolation suite.
 */
test('the same document can be in-house at another Zanzibar property', function (bool $otherOrganization) {
    $t = twoTenants();
    [$first] = documentGuardArrivals($t->a->property, $t->a->owner);
    $otherProperty = $otherOrganization ? $t->b->property : $t->a->otherProperty;
    $otherOwner = $otherOrganization ? $t->b->owner : $t->a->owner;
    $otherProperty->update(['jurisdiction_profile' => JurisdictionProfile::Zanzibar]);
    [$other] = documentGuardArrivals($otherProperty, $otherOwner);
    $this->actingAs($t->a->owner)->postJson(route('reservations.check-in', $first), ['_property' => $t->a->property->id])->assertSessionHasNoErrors();
    $this->actingAs($otherOwner)->withSession(['tenancy.organization_id' => $otherProperty->organization_id, 'tenancy.property_id' => $otherProperty->id])
        ->postJson(route('reservations.check-in', $other), ['_property' => $otherProperty->id])->assertSessionHasNoErrors();
    inTenant($otherProperty, fn () => expect($other->fresh()->status)->toBe(ReservationStatus::CheckedIn));
})->with(['same organization' => false, 'other organization' => true]);

/**
 * protects: correcting an in-house guest's document cannot make it collide with another in-house stay (ADR 0016).
 * fails_when: the edit saves, the refusal misses the other room, leaks the number, or is not audited.
 * no_claim: guests not in house, who meet the guard at check-in.
 */
test('editing an in-house guest to another in-house document is refused and names the room', function () {
    $t = twoTenants();
    [$first, $second, $guest] = documentGuardArrivals($t->a->property, $t->a->owner);
    inTenant($t->a->property, fn () => $guest->documents()->sole()->update(['number' => 'OTHER']));
    $this->actingAs($t->a->owner);
    foreach ([$first, $second] as $stay) {
        $this->postJson(route('reservations.check-in', $stay), ['_property' => $t->a->property->id])->assertSessionHasNoErrors();
    }
    $response = $this->putJson(route('guests.update', $guest), ['_property' => $t->a->property->id, 'name' => $guest->name, 'documents' => [['type' => 'passport', 'number' => 'p1234567', 'nationality' => 'DE']]])
        ->assertUnprocessable()->assertJsonValidationErrors('documents');
    expect($response->json('errors.documents.0'))->toContain('101')
        ->and($response->getContent())->not->toContain('P1234567');
    inTenant($t->a->property, function () use ($guest, $second) {
        expect($guest->documents()->sole()->getAttribute('number'))->toBe('OTHER')
            ->and($second->lifecycleEvents()->where('type', 'guest_profile_changed')->exists())->toBeFalse()
            ->and(Activity::query()->where('subject_id', $guest->id)->where('event', 'refused')->exists())->toBeTrue();
    });
});
