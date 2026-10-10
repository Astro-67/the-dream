<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\ReservationLifecycleEvent;
use App\Domains\Reservations\Models\ReservationNumberSequence;
use App\Domains\Reservations\Models\ReservationPriceLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;

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
