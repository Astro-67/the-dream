<?php

use App\Domains\Billing\Actions\OpenFolio;
use App\Domains\Billing\Models\Folio;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\AssignRoom;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\ReservationLifecycleEvent;
use App\Domains\Reservations\Models\ReservationNumberSequence;
use App\Domains\Reservations\Models\ReservationPriceLine;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;
use Tests\Support\TenantFixture;

function bookingGroupMembers(TenantFixture $tenant): array
{
    return inTenant($tenant->property, function () use ($tenant) {
        $details = BookingForm::details($tenant->owner);
        app(CreateRoom::class)->handle($tenant->owner, InventoryForm::room($details['room_type_id'], ['number' => '102']));
        app(CreateReservation::class)->handle($tenant->owner, [...$details, 'rooms' => 2]);

        return Reservation::query()->orderBy('reservation_number')->get()->all();
    });
}

test('group payment targets only the selected member folio', function () {
    $t = twoTenants();
    [$member, $sibling] = bookingGroupMembers($t->a);
    $folio = inTenant($t->a->property, fn () => Folio::query()->where('reservation_id', $sibling->id)->sole());
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->actingAs($t->a->owner)->get(route('reservations.show', $member))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('groupMembers.0.paymentFolios', 1)
            ->where('groupMembers.0.paymentFolios.0.id', $folio->id)
            ->where('groupMembers.0.paymentFolios.0.currency', 'USD')
            ->where('groupMembers.0.paymentFolios.0.total.due', 22000));
    $this->post(route('folios.payments.store', $folio), [
        '_property' => $t->a->property->id, 'method' => 'bank_transfer',
        'amount' => 5000, 'reference' => 'GROUP-187', 'idempotency_key' => 'group-payment-187',
    ])->assertSessionHasNoErrors()->assertRedirect();
    $this->get(route('reservations.show', $member))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('totals.0.balance', 0)
            ->where('totals.0.due', 22000)
            ->where('groupMembers.0.paymentFolios.0.total.balance', -5000)
            ->where('groupMembers.0.paymentFolios.0.total.due', 17000));
});

test('the reservation list identifies booking group membership', function () {
    $t = twoTenants();
    [$member, $sibling] = bookingGroupMembers($t->a);
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->actingAs($t->a->owner)->get(route('reservations.index', ['list' => 'all']))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('reservations.data', 2)
            ->where('reservations.data.0.booking_group_id', $member->booking_group_id)
            ->where('reservations.data.1.booking_group_id', $sibling->booking_group_id));
});

test('group departure actions respect status and staff permissions', function (OrganizationRole $role, string $status, bool $allowed) {
    $t = twoTenants();
    [$member, $sibling] = bookingGroupMembers($t->a);
    inTenant($t->a->property, fn () => $sibling->update(['status' => $status]));
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->actingAs($t->a->member($role))->get(route('reservations.show', $member))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('groupMembers.0.can.checkIn', false)
            ->where('groupMembers.0.can.checkOut', $allowed));
})->with([
    [OrganizationRole::FrontDesk, 'checked_in', true],
    [OrganizationRole::Auditor, 'checked_in', false],
    [OrganizationRole::FrontDesk, 'checked_out', false],
    [OrganizationRole::FrontDesk, 'cancelled', false],
    [OrganizationRole::FrontDesk, 'no_show', false],
    [OrganizationRole::FrontDesk, 'void', false],
]);

test('group payment choices retain each folio currency and reservation', function () {
    $t = twoTenants();
    [$member, $sibling] = bookingGroupMembers($t->a);
    $extra = inTenant($t->a->property, fn () => app(OpenFolio::class)->handle($t->a->owner, $sibling, 'TZS'));
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->actingAs($t->a->owner)->get(route('reservations.show', $member))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('groupMembers.0.paymentFolios', 2)
            ->where('groupMembers.0.paymentFolios.1.id', $extra->id)
            ->where('groupMembers.0.paymentFolios.1.currency', 'TZS')
            ->where('groupMembers.0.paymentFolios.1.total.currency', 'TZS')
            ->where('groupMembers.0.paymentFolios.1.total.due', 0));
});

test('booking group actions never expose members from another property or organization', function () {
    $t = twoTenants();
    [$member, $sibling] = bookingGroupMembers($t->a);
    foreach ([[$t->a->otherProperty, $t->a->owner], [$t->b->property, $t->b->owner]] as [$property, $owner]) {
        inTenant($property, function () use ($owner, $member) {
            $foreign = app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
            $foreign->update(['booking_group_id' => $member->booking_group_id]);
        });
    }
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->actingAs($t->a->owner)->get(route('reservations.show', $member))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('groupMembers', 1)
            ->where('groupMembers.0.reservation_number', $sibling->reservation_number));
});

test('group members offer their permitted arrival actions and checking in one leaves the other confirmed', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam'));
    $t = twoTenants();
    [$member, $sibling] = bookingGroupMembers($t->a);
    inTenant($t->a->property, function () use ($t, $sibling) {
        $sibling->update(['adults' => 1, 'children' => 0]);
        $room = Room::query()->orderBy('number')->first();
        app(AssignRoom::class)->handle($t->a->owner, $sibling, $room->id);
        RoomStatus::query()->where('room_id', $room->id)->update(['status' => HousekeepingStatus::Clean]);
        $guest = app(CreateGuest::class)->handle($t->a->owner, ['name' => 'Group guest', 'documents' => [['type' => 'passport', 'number' => 'P1234567', 'nationality' => 'DE']]]);
        ReservationGuest::query()->create(['reservation_id' => $sibling->id, 'guest_id' => $guest->id]);
    });
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->actingAs($t->a->owner)->get(route('reservations.show', $member))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('groupMembers.0.reservation_number', $sibling->reservation_number)
            ->where('groupMembers.0.can.checkIn', true)
            ->where('groupMembers.0.can.checkOut', false));
    $this->post(route('reservations.check-in', $sibling), ['_property' => $t->a->property->id])->assertSessionHasNoErrors()->assertRedirect();
    $this->get(route('reservations.show', $member))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reservation.status', 'confirmed')
            ->where('groupMembers.0.status', 'checked_in')
            ->where('groupMembers.0.can.checkIn', false)
            ->where('groupMembers.0.can.checkOut', true)
            ->has('groupMembers.0.checkoutBalances', 1));
    $this->get(route('reservations.show', $sibling))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reservation.status', 'checked_in')
            ->where('groupMembers.0.reservation_number', $member->reservation_number)
            ->where('groupMembers.0.status', 'confirmed'));
});

test('group arrival actions respect business date and staff permissions', function (OrganizationRole $role, string $date, bool $allowed) {
    $this->travelTo(CarbonImmutable::parse('2026-12-09 09:00:00', 'Africa/Dar_es_Salaam'));
    $t = twoTenants();
    [$member] = bookingGroupMembers($t->a);
    $t->a->property->update(['business_date' => $date]);
    $this->actingAs($t->a->member($role))->get(route('reservations.show', $member))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can.checkIn', $allowed)
            ->where('groupMembers.0.can.checkIn', $allowed)
            ->where('groupMembers.0.can.checkOut', false)
            ->has('groupMembers.0.paymentFolios', $role === OrganizationRole::Auditor ? 0 : 1));
})->with([
    [OrganizationRole::FrontDesk, '2026-12-10', true],
    [OrganizationRole::FrontDesk, '2026-12-11', true],
    [OrganizationRole::Auditor, '2026-12-10', false],
    [OrganizationRole::FrontDesk, '2026-12-09', false],
    [OrganizationRole::FrontDesk, '2026-12-12', false],
]);

test('front desk books several rooms with one booker and separate numbers prices and created events', function () {
    $t = twoTenants();
    $details = inTenant($t->a->property, function () use ($t) {
        $details = BookingForm::details($t->a->owner);
        app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($details['room_type_id'], ['number' => '102']));

        return $details;
    });
    $response = $this->actingAs($t->a->owner)->post(route('reservations.store'), [...$details, 'rooms' => 2, '_property' => $t->a->property->id]);
    $response->assertRedirect();
    inTenant($t->a->property, function () use ($details) {
        $members = Reservation::query()->orderBy('reservation_number')->get();
        expect($members)->toHaveCount(2)
            ->and($members->pluck('reservation_number')->unique())->toHaveCount(2)
            ->and($members->pluck('booking_group_id')->unique()->all())->toHaveCount(1)
            ->and($members->first()->booking_group_id)->not->toBeNull();
        foreach ($members as $member) {
            expect($member->booker_id)->toBe($details['booker_id'])
                ->and($member->priceLines->pluck('amount')->all())->toBe([11000, 11000])
                ->and($member->lifecycleEvents->sole()->type)->toBe('created');
        }
    });
});

test('group details link siblings and cancelling one member leaves the others confirmed', function () {
    $t = twoTenants();
    [$member, $sibling] = inTenant($t->a->property, function () use ($t) {
        $details = BookingForm::details($t->a->owner);
        app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($details['room_type_id'], ['number' => '102']));
        app(CreateReservation::class)->handle($t->a->owner, [...$details, 'rooms' => 2]);

        return Reservation::query()->orderBy('reservation_number')->get()->all();
    });
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)->get(route('reservations.show', $member))
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('groupMembers', 1)->where('groupMembers.0.reservation_number', $sibling->reservation_number));
    $this->post(route('reservations.cancel', $member), ['reason' => 'One guest withdrew', '_property' => $t->a->property->id])->assertRedirect();
    $this->get(route('reservations.show', $sibling))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reservation.status', 'confirmed')->where('groupMembers.0.status', 'cancelled'));
    inTenant($t->a->property, function () use ($member, $sibling) {
        expect($member->lifecycleEvents()->pluck('type')->all())->toBe(['created', 'cancelled'])
            ->and($sibling->lifecycleEvents()->pluck('type')->all())->toBe(['created'])
            ->and(InventoryRecord::query()->whereBetween('date', ['2026-12-10', '2026-12-11'])->orderBy('date')->pluck('sold_count')->all())->toBe([1, 1]);
    });
});

test('booking groups refuse invalid room counts without creating reservations', function (int $rooms) {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner, $rooms) {
        $details = BookingForm::details($owner);
        expect(fn () => app(CreateReservation::class)->handle($owner, [...$details, 'rooms' => $rooms]))->toThrow(ValidationException::class);
        expect(Reservation::query()->count())->toBe(0);
    });
})->with([0, -1, 21]);

test('an exhausted later night refuses the entire group without taking earlier inventory or numbers', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        app(CreateRoom::class)->handle($owner, InventoryForm::room($details['room_type_id'], ['number' => '102']));
        app(CreateReservation::class)->handle($owner, [...$details, 'arrival' => '2026-12-11']);
        expect(fn () => app(CreateReservation::class)->handle($owner, [...$details, 'rooms' => 2]))->toThrow(ValidationException::class);
        expect(Reservation::query()->count())->toBe(1)
            ->and(ReservationNumberSequence::query()->sole()->last_number)->toBe(1)
            ->and(InventoryRecord::query()->whereBetween('date', ['2026-12-10', '2026-12-11'])->orderBy('date')->pluck('sold_count')->all())->toBe([0, 1]);
    });
});

test('aborting a group transaction rolls back every member price event audit and inventory change', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        app(CreateRoom::class)->handle($owner, InventoryForm::room($details['room_type_id'], ['number' => '102']));
        expect(fn () => DB::transaction(function () use ($owner, $details) {
            app(CreateReservation::class)->handle($owner, [...$details, 'rooms' => 2]);
            throw new RuntimeException('Abort group');
        }))->toThrow(RuntimeException::class, 'Abort group');
        expect(Reservation::query()->count())->toBe(0)
            ->and(ReservationPriceLine::query()->count())->toBe(0)
            ->and(ReservationLifecycleEvent::query()->count())->toBe(0)
            ->and(ReservationNumberSequence::query()->count())->toBe(0)
            ->and((int) InventoryRecord::query()->sum('sold_count'))->toBe(0)
            ->and(Activity::query()->where('subject_type', Reservation::class)->count())->toBe(0);
    });
});

test('group membership cannot expose reservations from another property or organization', function () {
    $t = twoTenants();
    $member = inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    $member = inTenant($t->a->property, function () use ($member) {
        $member->update(['booking_group_id' => (string) Str::ulid()]);

        return $member;
    });
    foreach ([$t->a->otherProperty, $t->b->property] as $property) {
        inTenant($property, function () use ($t, $property, $member) {
            $actor = $property->id === $t->b->property->id ? $t->b->owner : $t->a->owner;
            $foreign = app(CreateReservation::class)->handle($actor, BookingForm::details($actor));
            $foreign->update(['booking_group_id' => $member->booking_group_id]);
        });
    }
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)
        ->get(route('reservations.show', $member))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('groupMembers', 0));
});

test('the group booking form rejects invalid room counts', function (mixed $rooms) {
    $t = twoTenants();
    $details = inTenant($t->a->property, fn () => BookingForm::details($t->a->owner));
    $this->actingAs($t->a->owner)->postJson(route('reservations.store'), [...$details, 'rooms' => $rooms, '_property' => $t->a->property->id])
        ->assertUnprocessable()->assertJsonValidationErrors('rooms');
    expect(inTenant($t->a->property, fn () => Reservation::query()->count()))->toBe(0);
})->with([0, -1, 21, 1.5, 'invalid']);

test('a group can assign its first reservation without assigning the same room to its siblings', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        $room = Room::query()->sole();
        app(CreateRoom::class)->handle($owner, InventoryForm::room($details['room_type_id'], ['number' => '102']));
        app(CreateReservation::class)->handle($owner, [...$details, 'rooms' => 2, 'room_id' => $room->id, 'source' => 'outside-integration', 'channel_reference' => 'OTA-GROUP-123', 'outside_integration_reason' => 'Channel outage']);
        $members = Reservation::query()->orderBy('reservation_number')->get();
        expect($members->pluck('room_id')->all())->toBe([$room->id, null]);
        foreach ($members as $member) {
            expect($member->requires_reconciliation)->toBeTrue()
                ->and($member->channel_reference)->toBe('OTA-GROUP-123')
                ->and($member->outside_integration_reason)->toBe('Channel outage')
                ->and($member->lifecycleEvents->sole()->type)->toBe('created')
                ->and($member->lifecycleEvents->sole()->snapshot['room_id'])->toBe($member->room_id)
                ->and($member->lifecycleEvents->sole()->snapshot['requires_reconciliation'])->toBeTrue()
                ->and($member->lifecycleEvents->sole()->snapshot['channel_reference'])->toBe('OTA-GROUP-123');
        }
    });
});
