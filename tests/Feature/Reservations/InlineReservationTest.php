<?php

use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Reservations\Enums\PayingInstitutionType;
use App\Domains\Reservations\Models\Reservation;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;

test('inline lead guest reserves without a booker and records inclusions', function () {
    $t = twoTenants();
    $details = inTenant($t->a->property, fn () => BookingForm::details($t->a->owner));
    unset($details['booker_id']);
    $response = $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)->post(route('reservations.store'), [
        ...$details, '_property' => $t->a->property->id,
        'lead_guest' => ['first_name' => 'Asha', 'last_name' => 'Ali', 'nationality' => 'TZ'],
        'meal_plan' => 'half-board', 'source' => 'whatsapp', 'special_requests' => 'Quiet room', 'nightly_rate' => 9500,
        'booked_through' => 'tour-operator', 'tour_operator_name' => 'Island Tours', 'tour_operator_registration' => 'ZRA-123', 'tour_operator_pays_for' => 'room-and-levy',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->get($response->headers->get('Location'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('booker', null)->where('leadGuest.name', 'Asha Ali')->where('reservation.meal_plan', 'half-board')
        ->where('reservation.source', 'whatsapp')->where('reservation.special_requests', 'Quiet room')
        ->where('reservation.tour_operator_registration', 'ZRA-123')->where('reservation.price_lines.0.amount', 9500));
    inTenant($t->a->property, fn () => expect(Reservation::query()->sole()->getAttribute('booker_id'))->toBeNull());
});

test('tour operator requires registration', function () {
    $t = twoTenants();
    $details = inTenant($t->a->property, fn () => BookingForm::details($t->a->owner));
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)->postJson(route('reservations.store'), [
        ...$details, '_property' => $t->a->property->id, 'booked_through' => 'tour-operator', 'tour_operator_name' => 'Tours', 'tour_operator_pays_for' => 'room-only',
    ])->assertUnprocessable()->assertJsonValidationErrors('tour_operator_registration');
});

test('walk-in rules follow property settings', function () {
    $t = twoTenants();
    $details = inTenant($t->a->property, fn () => BookingForm::details($t->a->owner));
    $t->a->property->update(['business_date' => '2026-12-10', 'front_desk_settings' => ['walk_ins_enabled' => false]]);
    $payload = [...$details, '_property' => $t->a->property->id, 'source' => 'walk-in', 'lead_guest' => ['first_name' => 'Asha', 'nationality' => 'TZ']];
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)->postJson(route('reservations.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('source');
    $t->a->property->update(['front_desk_settings' => ['walk_ins_enabled' => true, 'walk_in_requires_id' => true]]);
    $this->postJson(route('reservations.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('lead_guest.document_type');
    $payload['lead_guest'] += ['document_type' => 'nida', 'document_number' => '12345'];
    $this->postJson(route('reservations.store'), [...$payload, 'arrival' => '2026-12-11'])->assertUnprocessable()->assertJsonValidationErrors('arrival');
    $this->postJson(route('reservations.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
});

test('foreign guest document type follows passport setting', function () {
    $t = twoTenants();
    $details = inTenant($t->a->property, fn () => BookingForm::details($t->a->owner));
    $payload = [...$details, '_property' => $t->a->property->id, 'lead_guest' => ['first_name' => 'Sam', 'last_name' => 'Lee', 'nationality' => 'GB', 'document_type' => 'nida', 'document_number' => '12345']];
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)->postJson(route('reservations.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('lead_guest.document_type');
    $t->a->property->update(['front_desk_settings' => ['passport_required_for_foreign_guests' => false]]);
    $this->postJson(route('reservations.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
});

test('new reservation sources are stored without a booker or ID', function (string $source) {
    $t = twoTenants();
    $details = inTenant($t->a->property, fn () => BookingForm::details($t->a->owner));
    unset($details['booker_id']);
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)
        ->post(route('reservations.store'), [...$details, '_property' => $t->a->property->id, 'source' => $source, 'lead_guest' => ['first_name' => 'Sam', 'last_name' => 'Lee', 'nationality' => 'GB']])
        ->assertRedirect()->assertSessionHasNoErrors();
    inTenant($t->a->property, fn () => expect(Reservation::query()->sole()->getAttribute('source'))->toBe($source));
})->with(['whatsapp', 'email', 'website']);

test('walk-in without ID is allowed when the property switches the requirement off', function () {
    $t = twoTenants();
    $details = inTenant($t->a->property, fn () => BookingForm::details($t->a->owner));
    unset($details['booker_id']);
    $t->a->property->update(['business_date' => '2026-12-10', 'front_desk_settings' => ['walk_in_requires_id' => false]]);
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)
        ->post(route('reservations.store'), [...$details, '_property' => $t->a->property->id, 'source' => 'walk-in', 'lead_guest' => ['first_name' => 'Asha']])
        ->assertRedirect()->assertSessionHasNoErrors();
});

test('a two-guest booking requires its lead guest but can leave the second guest for registration', function () {
    $t = twoTenants();
    $details = inTenant($t->a->property, fn () => BookingForm::details($t->a->owner));
    unset($details['booker_id']);
    $payload = [...$details, '_property' => $t->a->property->id, 'adults' => 2, 'children' => 0];
    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->postJson(route('reservations.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('lead_guest');
    $this->post(route('reservations.store'), [...$payload, 'lead_guest' => ['first_name' => 'Lead', 'last_name' => 'Occupant']])
        ->assertSessionHasNoErrors()->assertRedirect();
    inTenant($t->a->property, function () {
        $stay = Reservation::query()->sole();
        expect($stay->adults)->toBe(2)->and($stay->children)->toBe(0)
            ->and($stay->leadGuest()->name)->toBe('Lead Occupant')
            ->and(ReservationGuest::query()->where('reservation_id', $stay->id)->count())->toBe(1);
    });
});

// protects=extra adults can be registered on the booking form; fails_when=the second adult is lost or attached to every room.
test('a booking can register its other adults now, each optional, and refuses more than booked', function () {
    $t = twoTenants();
    $details = inTenant($t->a->property, fn () => BookingForm::details($t->a->owner));
    unset($details['booker_id']);
    $payload = [...$details, '_property' => $t->a->property->id, 'adults' => 2, 'children' => 1, 'lead_guest' => ['first_name' => 'Lead', 'last_name' => 'Occupant']];
    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id]);
    $this->postJson(route('reservations.store'), [...$payload, 'guests' => [['name' => 'A'], ['name' => 'B']]])
        ->assertUnprocessable()->assertJsonValidationErrors('guests');
    $this->postJson(route('reservations.store'), [...$payload, 'guests' => [['name' => 'A', 'nationality' => 'DE', 'document_type' => 'nida', 'document_number' => 'N1']]])
        ->assertUnprocessable()->assertJsonValidationErrors('guests.0.document_type');

    $this->post(route('reservations.store'), [...$payload, 'guests' => [
        ['name' => 'Second Adult', 'nationality' => 'DE', 'document_type' => 'passport', 'document_number' => 'P2'],
        ['name' => ''],
    ]])->assertSessionHasNoErrors()->assertRedirect();
    inTenant($t->a->property, function () {
        $stay = Reservation::query()->sole();
        $guests = Guest::query()->whereIn('id', ReservationGuest::query()->where('reservation_id', $stay->id)->select('guest_id'))->with('documents')->orderBy('id')->get();
        expect($guests->pluck('name')->all())->toBe(['Lead Occupant', 'Second Adult'])
            ->and($guests[1]->documents->sole()->only(['type', 'number', 'nationality']))->toBe(['type' => 'passport', 'number' => 'P2', 'nationality' => 'DE']);
    });
});

// protects=a paying institution needs a name and ZRB number, and none is stored without a type (spec #242); fails_when=a typed institution saves without its number, or stray fields are stored; no_claim=ZRB number format, which ZRA checks.
test('a paying institution requires its name and ZRB number, and is excluded without a type', function () {
    $t = twoTenants();
    $details = inTenant($t->a->property, fn () => BookingForm::details($t->a->owner));
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner);

    $this->postJson(route('reservations.store'), [...$details, '_property' => $t->a->property->id, 'paying_institution_type' => 'government'])
        ->assertUnprocessable()->assertJsonValidationErrors(['paying_institution_name', 'paying_institution_zrb_number']);
    $this->postJson(route('reservations.store'), [...$details, '_property' => $t->a->property->id, 'paying_institution_type' => 'charity', 'paying_institution_name' => 'A', 'paying_institution_zrb_number' => 'B'])
        ->assertUnprocessable()->assertJsonValidationErrors('paying_institution_type');
    $this->postJson(route('reservations.store'), [...$details, '_property' => $t->a->property->id, 'paying_institution_type' => 'business', 'paying_institution_name' => str_repeat('a', 256), 'paying_institution_zrb_number' => 'Z1'])
        ->assertUnprocessable()->assertJsonValidationErrors('paying_institution_name');
    $this->post(route('reservations.store'), [...$details, '_property' => $t->a->property->id, 'paying_institution_type' => '', 'paying_institution_name' => 'Stray', 'paying_institution_zrb_number' => 'Z9'])
        ->assertRedirect()->assertSessionHasNoErrors();

    inTenant($t->a->property, fn () => expect(Reservation::query()->sole()->only(['paying_institution_type', 'paying_institution_name', 'paying_institution_zrb_number']))
        ->toBe(['paying_institution_type' => null, 'paying_institution_name' => null, 'paying_institution_zrb_number' => null]));
});

// protects=every room of a booking group is filed for the same paying institution (spec #242 story 3); fails_when=a later room loses the institution; no_claim=correcting it after booking (#244).
test('every room of a booking group gets the paying institution, trimmed', function () {
    $t = twoTenants();
    $details = inTenant($t->a->property, function () use ($t) {
        $details = BookingForm::details($t->a->owner);
        app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($details['room_type_id'], ['number' => '102']));

        return $details;
    });
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)->post(route('reservations.store'), [
        ...$details, '_property' => $t->a->property->id, 'rooms' => 2, 'adults' => 2,
        'paying_institution_type' => 'government', 'paying_institution_name' => '  Ministry of Health ', 'paying_institution_zrb_number' => ' WA2400117 ',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $members = inTenant($t->a->property, fn () => Reservation::query()->orderBy('reservation_number')->get());
    expect($members)->toHaveCount(2);
    foreach ($members as $member) {
        expect($member->only(['paying_institution_type', 'paying_institution_name', 'paying_institution_zrb_number']))
            ->toEqual(['paying_institution_type' => PayingInstitutionType::Government, 'paying_institution_name' => 'Ministry of Health', 'paying_institution_zrb_number' => 'WA2400117']);
    }
    $this->get(route('reservations.show', $members[1]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('reservation.paying_institution_type', 'government')
        ->where('reservation.paying_institution_name', 'Ministry of Health')
        ->where('reservation.paying_institution_zrb_number', 'WA2400117'));
});

// protects=a paying institution is stored only where the property files with VFMS, even from a direct request (spec #242); fails_when=a Mainland or demo booking stores the fields; no_claim=the booking form's display.
test('a booking on a Mainland or demo property stores no paying institution', function (string $where) {
    $t = twoTenants();
    $property = $where === 'Mainland' ? $t->a->otherProperty : $t->a->property;
    if ($where === 'demo') {
        DB::table('properties')->where('id', $property->id)->update(['demo' => true]);
    }
    $details = inTenant($property, fn () => BookingForm::details($t->a->owner));

    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $property->id])->actingAs($t->a->owner)->post(route('reservations.store'), [
        ...$details, '_property' => $property->id, 'paying_institution_type' => 'business', 'paying_institution_name' => 'Island Supplies Ltd', 'paying_institution_zrb_number' => 'Z1',
    ])->assertRedirect()->assertSessionHasNoErrors();

    inTenant($property, fn () => expect(Reservation::query()->sole()->only(['paying_institution_type', 'paying_institution_name', 'paying_institution_zrb_number']))
        ->toBe(['paying_institution_type' => null, 'paying_institution_name' => null, 'paying_institution_zrb_number' => null]));
})->with(['Mainland', 'demo']);
