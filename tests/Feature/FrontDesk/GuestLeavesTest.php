<?php

use App\Domains\FrontDesk\Actions\AddGuest;
use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\FrontDesk\Actions\CheckOut;
use App\Domains\FrontDesk\Actions\CloseBusinessDate;
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
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Support\Outbox\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;
use Tests\Support\TenantFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam'));
    Http::preventStrayRequests();
});

/** Lead guest Anna Berg (P1234567) and Ola Berg (P7654321) in room 101, checked in unless $checkIn is false. */
function guestLeavesStay(TenantFixture $tenant, bool $checkIn = true): Reservation
{
    return inTenant($tenant->property, function () use ($tenant, $checkIn) {
        $details = BookingForm::details($tenant->owner);
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        $reservation = app(CreateReservation::class)->handle($tenant->owner, [...$details, 'room_id' => Room::query()->sole()->id]);
        foreach ([['Anna Berg', 'P1234567'], ['Ola Berg', 'P7654321']] as [$name, $number]) {
            $guest = app(CreateGuest::class)->handle($tenant->owner, ['name' => $name, 'documents' => [['type' => 'passport', 'number' => $number, 'nationality' => 'DE']]]);
            ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);
        }

        return $checkIn ? app(CheckIn::class)->handle($tenant->owner, $reservation) : $reservation;
    });
}

function guestLeavesGuest(TenantFixture $tenant, string $name): Guest
{
    return inTenant($tenant->property, fn () => Guest::query()->where('name', $name)->sole());
}

function guestLeavesPost(TenantFixture $tenant, Reservation $reservation, Guest $guest, OrganizationRole $role = OrganizationRole::FrontDesk)
{
    return test()->actingAs($tenant->member($role))->post(route('reservations.guest-leaves', $reservation), ['_property' => $tenant->property->id, 'guest_id' => $guest->id]);
}

// protects=one guest leaving an in-house Sandbox stay files guide §2.8 for that document only, and the stay continues (#164); fails_when=no message, another guest's document is listed, the message is not accepted, or the stay or room leaves in-house; no_claim=VFMS production stops the levy.
test('a guest leaving an in-house Sandbox stay files one accepted check-out for their document only', function () {
    $t = twoTenants();
    $reservation = guestLeavesStay($t->a);

    guestLeavesPost($t->a, $reservation, guestLeavesGuest($t->a, 'Ola Berg'))->assertRedirect()->assertSessionHasNoErrors();

    $messages = inTenant($t->a->property, fn () => OutboxMessage::query()->where('integration', 'vfms')->where('event', '!=', 'accommodation')->get()->all());
    expect($messages)->toHaveCount(1);
    [$message] = $messages;
    expect($message->status)->toBe('delivered')
        ->and($message->payload['request_type'])->toBe('ACCOMMODATION_CHECK_OUT')
        ->and($message->payload['path'])->toBe('guest/checkout/')
        ->and($message->payload['body'])->toBe(['documentNumberList' => [['documentNumber' => 'P7654321']], 'currency' => 'USD']);
    inTenant($t->a->property, fn () => expect($reservation->fresh()->status)->toBe(ReservationStatus::CheckedIn)
        ->and(RoomStatus::query()->where('room_id', $reservation->room_id)->sole()->status)->toBe(HousekeepingStatus::Occupied)
        ->and($reservation->lifecycleEvents()->where('type', 'guest_departed')->sole()->snapshot['guest_id'])->toBe(guestLeavesGuest($t->a, 'Ola Berg')->id));
});

// protects=the stay's final §2.8 check-out names only guests still in-house, so VFMS never checks out a departed guest twice (#164); fails_when=the departed guest's document is listed again or the lead guest's is missing; no_claim=how VFMS treats a repeated document.
test('the stay check-out after a guest left lists only the remaining guest', function () {
    $t = twoTenants();
    $reservation = guestLeavesStay($t->a);
    guestLeavesPost($t->a, $reservation, guestLeavesGuest($t->a, 'Ola Berg'))->assertSessionHasNoErrors();

    foreach (['2026-12-10', '2026-12-11'] as $date) {
        inTenant($t->a->property, fn () => app(CloseBusinessDate::class)->handle($t->a->owner, $t->a->property->fresh(), $date));
    }
    inTenant($t->a->property, fn () => app(CheckOut::class)->handle($t->a->owner, $reservation, 'Guest settles at the desk'));

    $checkout = inTenant($t->a->property, fn () => OutboxMessage::query()->where('integration', 'vfms')->where('event', 'checkout')->sole());
    expect($checkout->status)->toBe('delivered')
        ->and($checkout->payload['body']['documentNumberList'])->toBe([['documentNumber' => 'P1234567']]);
});

// protects=Guest leaves only ever acts on a non-lead guest still in an in-house stay (#164); fails_when=the lead guest, a guest already gone, or a stay not in-house is accepted, or a refusal still records an event or a VFMS message; no_claim=wording of the refusal.
test('guest leaves is refused for the lead guest, a departed guest and a stay not in-house', function (string $name, bool $checkIn, bool $leaveFirst, string $field) {
    $t = twoTenants();
    $reservation = guestLeavesStay($t->a, $checkIn);
    $guest = guestLeavesGuest($t->a, $name);
    if ($leaveFirst) {
        guestLeavesPost($t->a, $reservation, $guest)->assertSessionHasNoErrors();
    }

    guestLeavesPost($t->a, $reservation, $guest)->assertSessionHasErrors($field);

    inTenant($t->a->property, fn () => expect($reservation->lifecycleEvents()->where('type', 'guest_departed')->count())->toBe($leaveFirst ? 1 : 0)
        ->and(OutboxMessage::query()->where('integration', 'vfms')->where('event', 'guestDeparture')->count())->toBe($leaveFirst ? 1 : 0));
})->with([
    'lead guest' => ['Anna Berg', true, false, 'guest_id'],
    'already departed' => ['Ola Berg', true, true, 'guest_id'],
    'stay not in-house' => ['Ola Berg', false, false, 'reservation'],
]);

// protects=Guest leaves follows the check-out permission (#164); fails_when=a role that cannot check out records a departure, or one that can is refused; no_claim=the role-permission matrix itself.
test('roles without check-out permission cannot record a guest leaving', function (OrganizationRole $role, bool $allowed) {
    $t = twoTenants();
    $reservation = guestLeavesStay($t->a);

    $response = guestLeavesPost($t->a, $reservation, guestLeavesGuest($t->a, 'Ola Berg'), $role);

    $allowed ? $response->assertRedirect()->assertSessionHasNoErrors() : $response->assertForbidden();
    inTenant($t->a->property, fn () => expect($reservation->lifecycleEvents()->where('type', 'guest_departed')->exists())->toBe($allowed));
})->with([
    'front desk' => [OrganizationRole::FrontDesk, true],
    'property manager' => [OrganizationRole::PropertyManager, true],
    'finance' => [OrganizationRole::Finance, false],
    'housekeeping' => [OrganizationRole::Housekeeping, false],
    'auditor' => [OrganizationRole::Auditor, false],
]);

// protects=the guests page offers Guest leaves only where it can succeed and shows who has left (#164); fails_when=the lead guest is not identified, a departed guest is not marked, or a role without check-out is offered the action; no_claim=the rendered layout.
test('the guests page shows the lead guest, who has left and whether a departure can be recorded', function () {
    $t = twoTenants();
    $reservation = guestLeavesStay($t->a);
    $lead = guestLeavesGuest($t->a, 'Anna Berg');
    $second = guestLeavesGuest($t->a, 'Ola Berg');
    guestLeavesPost($t->a, $reservation, $second)->assertSessionHasNoErrors();

    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get(route('reservations.guests.create', $reservation))
        ->assertInertia(fn (Assert $page) => $page->where('leadGuestId', $lead->id)->where('departedGuestIds', [$second->id])->where('canRecordDeparture', true));
    $this->actingAs($t->a->member(OrganizationRole::Auditor))->get(route('reservations.guests.create', $reservation))
        ->assertInertia(fn (Assert $page) => $page->where('canRecordDeparture', false));
});

// protects=a guest who has left is no longer in-house for the document guard (ADR 0016, #164); fails_when=their document is still refused on another stay at the property; no_claim=re-adding them to the stay they left.
test('a guest who left can be added to another in-house stay at the property', function () {
    $t = twoTenants();
    $reservation = guestLeavesStay($t->a);
    $ola = guestLeavesGuest($t->a, 'Ola Berg');
    $other = inTenant($t->a->property, function () use ($t, $reservation) {
        $room = app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($reservation->room_type_id, ['number' => '202']));
        RoomStatus::query()->where('room_id', $room->id)->update(['status' => HousekeepingStatus::Clean]);
        $stay = app(CreateReservation::class)->handle($t->a->owner, [...$reservation->only(['room_type_id', 'rate_plan_id', 'booker_id', 'adults', 'children', 'source']), 'arrival' => '2026-12-10', 'departure' => '2026-12-12', 'room_id' => $room->id]);
        $guest = app(CreateGuest::class)->handle($t->a->owner, ['name' => 'Kim Lee', 'documents' => [['type' => 'passport', 'number' => 'K1112223', 'nationality' => 'DE']]]);
        ReservationGuest::query()->create(['reservation_id' => $stay->id, 'guest_id' => $guest->id]);

        return app(CheckIn::class)->handle($t->a->owner, $stay);
    });
    guestLeavesPost($t->a, $reservation, $ola)->assertSessionHasNoErrors();

    inTenant($t->a->property, fn () => app(AddGuest::class)->handle($t->a->owner, $other, ['guest_id' => $ola->id]));

    expect(inTenant($t->a->property, fn () => ReservationGuest::query()->where(['reservation_id' => $other->id, 'guest_id' => $ola->id])->exists()))->toBeTrue();
});
