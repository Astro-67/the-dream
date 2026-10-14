<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Inventory\Models\Room;
use App\Domains\Rates\Actions\CreateRatePlan;
use App\Domains\Rates\Actions\SetRatePrices;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Rates\Models\RatePrice;
use App\Domains\Rates\Models\RateRestriction;
use App\Domains\Reservations\Actions\AmendReservation;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Actions\RecordLifecycleEvent;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\ReservationLifecycleEvent;
use App\Domains\Reservations\Models\RoomAssignmentNight;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;

test('front desk amends a confirmed stay with atomic inventory and visible history', function () {
    $t = twoTenants();
    [$reservation, $details] = inTenant($t->a->property, function () use ($t) {
        $details = BookingForm::details($t->a->owner);

        return [app(CreateReservation::class)->handle($t->a->owner, $details), $details];
    });
    $actor = $t->a->member(OrganizationRole::FrontDesk);
    $this->actingAs($actor);
    $this->patch(route('reservations.amend', $reservation), [...$details, 'arrival' => '2026-12-11', 'children' => 0, 'room_id' => null, 'pricing' => 'keep', '_property' => $t->a->property->id])->assertRedirect(route('reservations.show', $reservation));
    $this->get(route('reservations.show', $reservation))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('reservation.arrival', '2026-12-11T00:00:00.000000Z')
        ->has('reservation.price_lines', 1)
        ->where('reservation.price_lines.0.amount', 11000)
        ->has('amendments.data', 1)
        ->where('amendments.data.0.actor.name', $actor->name)
        ->where('amendments.data.0.snapshot.before.children', 1)
        ->where('amendments.data.0.snapshot.after.children', 0));
    inTenant($t->a->property, function () use ($reservation) {
        expect(InventoryRecord::query()->whereBetween('date', ['2026-12-10', '2026-12-11'])->orderBy('date')->pluck('sold_count')->all())->toBe([0, 1]);
        expect($reservation->fresh()->status)->toBe(ReservationStatus::Confirmed)
            ->and($reservation->lifecycleEvents()->pluck('type')->all())->toBe(['created', 'modified']);
    });
});

test('amendment form exposes property choices and pricing decision on the detail page', function () {
    $t = twoTenants();
    $reservation = inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page
        ->where('canAmend', true)->has('amendmentOptions.roomTypes', 1)->has('amendmentOptions.ratePlans', 1)->has('amendmentOptions.rooms', 1));
});

test('amendment cannot assign a room without rooms assign permission', function () {
    $t = twoTenants();
    [$reservation, $details, $room] = inTenant($t->a->property, function () use ($t) {
        $details = BookingForm::details($t->a->owner);

        return [app(CreateReservation::class)->handle($t->a->owner, $details), $details, Room::query()->sole()];
    });
    $actor = $t->a->member(OrganizationRole::FrontDesk);
    inTenant($t->a->property, function () use ($actor) {
        $actor->syncRoles([]);
        $actor->givePermissionTo('reservations.manage');
    });
    $this->actingAs($actor)->patchJson(route('reservations.amend', $reservation), [...$details, 'room_id' => $room->id, 'pricing' => 'keep', '_property' => $t->a->property->id])->assertForbidden();
});

test('keeping prices ignores missing rates for retained nights and prices only added nights', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        $reservation = app(CreateReservation::class)->handle($owner, $details);
        RatePrice::query()->delete();
        app(SetRatePrices::class)->handle($owner, RatePlan::query()->sole(), ['room_type_id' => $details['room_type_id'], 'from' => '2026-12-12', 'to' => '2026-12-12', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'base_price' => 25000, 'extra_adult' => 0, 'extra_child' => 0]);
        $amended = app(AmendReservation::class)->handle($owner, $reservation, [...$details, 'departure' => '2026-12-13', 'room_id' => null, 'pricing' => 'keep']);
        expect($amended->priceLines->pluck('amount')->all())->toBe([11000, 11000, 25000]);
        expect($amended->lifecycleEvents()->reorder('sequence', 'desc')->first()->snapshot['price_lines'])->toHaveCount(3);
    });
});

test('re-pricing preserves past lines and replaces only future prices in property local time', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['timezone' => 'Africa/Dar_es_Salaam']);
    inTenant($property, function () use ($owner, $property) {
        $details = BookingForm::details($owner);
        $reservation = app(CreateReservation::class)->handle($owner, $details);
        $property->update(['business_date' => '2026-12-11']);
        RatePrice::query()->update(['base_price' => 30000]);
        $amended = app(AmendReservation::class)->handle($owner, $reservation, [...$details, 'room_id' => null, 'pricing' => 'reprice']);
        expect($amended->priceLines->pluck('amount')->all())->toBe([11000, 31000]);
    });
});

test('amendment oversell refusal rolls back released inventory prices assignment and history', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        $room = Room::query()->sole();
        $reservation = app(CreateReservation::class)->handle($owner, [...$details, 'departure' => '2026-12-11', 'room_id' => $room->id]);
        app(CreateReservation::class)->handle($owner, [...$details, 'arrival' => '2026-12-11']);
        expect(fn () => app(AmendReservation::class)->handle($owner, $reservation, [...$details, 'arrival' => '2026-12-11', 'room_id' => null, 'pricing' => 'reprice']))->toThrow(ValidationException::class);
        expect($reservation->fresh()->arrival->toDateString())->toBe('2026-12-10')
            ->and($reservation->fresh()->room_id)->toBe($room->id)
            ->and($reservation->priceLines()->pluck('amount')->all())->toBe([11000])
            ->and($reservation->lifecycleEvents()->pluck('type')->all())->toBe(['created'])
            ->and(RoomAssignmentNight::query()->where('reservation_id', $reservation->id)->pluck('date')->map->toDateString()->all())->toBe(['2026-12-10'])
            ->and(InventoryRecord::query()->whereBetween('date', ['2026-12-10', '2026-12-11'])->orderBy('date')->pluck('sold_count')->all())->toBe([1, 1]);
    });
});

test('amendment restriction warnings require a reason and retain the override in history', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        $reservation = app(CreateReservation::class)->handle($owner, $details);
        RateRestriction::query()->create(['rate_plan_id' => $details['rate_plan_id'], 'room_type_id' => $details['room_type_id'], 'date' => $details['arrival'], 'min_stay' => 3, 'closed_to_arrival' => true, 'stop_sell' => true]);
        expect(fn () => app(AmendReservation::class)->handle($owner, $reservation, [...$details, 'room_id' => null, 'pricing' => 'keep']))->toThrow(ValidationException::class);
        expect($reservation->lifecycleEvents()->count())->toBe(1);
        $amended = app(AmendReservation::class)->handle($owner, $reservation, [...$details, 'room_id' => null, 'pricing' => 'keep', 'restriction_override_reason' => ' Returning guest ']);
        expect($amended->getAttribute('restriction_override_reason'))->toBe('Returning guest');
        $event = $amended->lifecycleEvents()->reorder('sequence', 'desc')->first();
        expect($event->snapshot['after']['restriction_override_reason'])->toBe('Returning guest');
    });
});

test('amendment regenerates retained room assignment nights and rejects an overlap atomically', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        $room = Room::query()->sole();
        app(CreateRoom::class)->handle($owner, InventoryForm::room($details['room_type_id'], ['number' => '102']));
        $reservation = app(CreateReservation::class)->handle($owner, [...$details, 'departure' => '2026-12-11', 'room_id' => $room->id]);
        app(CreateReservation::class)->handle($owner, [...$details, 'arrival' => '2026-12-11', 'room_id' => $room->id]);
        expect(fn () => app(AmendReservation::class)->handle($owner, $reservation, [...$details, 'room_id' => $room->id, 'pricing' => 'keep']))->toThrow(ValidationException::class);
        expect($reservation->fresh()->departure->toDateString())->toBe('2026-12-11')
            ->and($reservation->lifecycleEvents()->count())->toBe(1)
            ->and($reservation->priceLines()->count())->toBe(1);
        $amended = app(AmendReservation::class)->handle($owner, $reservation, [...$details, 'arrival' => '2026-12-11', 'room_id' => Room::query()->where('number', '102')->sole()->id, 'pricing' => 'reprice']);
        expect(RoomAssignmentNight::query()->where('reservation_id', $reservation->id)->pluck('date')->map->toDateString()->all())->toBe(['2026-12-11'])
            ->and($amended->lifecycleEvents()->pluck('type')->all())->toBe(['created', 'modified']);
    });
});

test('amendment validates assigned room constraints and rolls back the complete change', function (string $invalid) {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner, $invalid) {
        $details = BookingForm::details($owner);
        $reservation = app(CreateReservation::class)->handle($owner, $details);
        $room = Room::query()->sole();
        if ($invalid === 'inactive') {
            $room->deactivated_at = now();
            $room->save();
        } elseif ($invalid === 'wrong type') {
            $other = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType(['code' => 'OTHER']));
            $room->update(['room_type_id' => $other->id]);
        } else {
            $room->outOfServicePeriods()->create(['starts_on' => '2026-12-10', 'returns_on' => '2026-12-12', 'reason' => 'Repair']);
        }
        expect(fn () => app(AmendReservation::class)->handle($owner, $reservation, [...$details, 'children' => 0, 'room_id' => $room->id, 'pricing' => 'reprice']))->toThrow(ValidationException::class);
        expect($reservation->fresh()->getAttribute('children'))->toBe(1)
            ->and($reservation->priceLines()->pluck('amount')->all())->toBe([11000, 11000])
            ->and($reservation->lifecycleEvents()->count())->toBe(1);
    });
})->with(['inactive', 'wrong type', 'out of service']);

test('amendment can change room type and rate plan without changing booking identity', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        $reservation = app(CreateReservation::class)->handle($owner, $details);
        $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType(['code' => 'NEW']));
        app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id, ['number' => '201']));
        $plan = app(CreateRatePlan::class)->handle($owner, ['name' => 'New plan', 'code' => 'NEW', 'currency' => 'USD', 'room_type_ids' => [$type->id]]);
        app(SetRatePrices::class)->handle($owner, $plan, ['room_type_id' => $type->id, 'from' => '2026-12-10', 'to' => '2026-12-11', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'base_price' => 40000, 'extra_adult' => 0, 'extra_child' => 0]);
        $amended = app(AmendReservation::class)->handle($owner, $reservation, [...$details, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id, 'room_id' => null, 'pricing' => 'reprice']);
        expect($amended->reservation_number)->toBe($reservation->reservation_number)
            ->and($amended->getAttribute('booker_id'))->toBe($details['booker_id'])
            ->and($amended->priceLines->pluck('amount')->all())->toBe([40000, 40000])
            ->and((int) InventoryRecord::query()->where('room_type_id', $details['room_type_id'])->sum('sold_count'))->toBe(0)
            ->and((int) InventoryRecord::query()->where('room_type_id', $type->id)->sum('sold_count'))->toBe(2);
    });
});

test('amendment is refused outside confirmed status', function (ReservationStatus $status) {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner, $status) {
        $details = BookingForm::details($owner);
        $reservation = app(CreateReservation::class)->handle($owner, $details);
        $reservation->update(['status' => $status]);
        expect(fn () => app(AmendReservation::class)->handle($owner, $reservation, [...$details, 'room_id' => null, 'pricing' => 'keep']))->toThrow(ValidationException::class);
        expect($reservation->lifecycleEvents()->count())->toBe(1);
    });
})->with([ReservationStatus::Cancelled, ReservationStatus::Void, ReservationStatus::CheckedIn, ReservationStatus::CheckedOut, ReservationStatus::NoShow]);

test('amendment route follows reservations manage permissions', function (OrganizationRole $role, bool $allowed) {
    $t = twoTenants();
    [$reservation, $details] = inTenant($t->a->property, function () use ($t) {
        $details = BookingForm::details($t->a->owner);

        return [app(CreateReservation::class)->handle($t->a->owner, $details), $details];
    });
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->member($role))
        ->patchJson(route('reservations.amend', $reservation), [...$details, 'room_id' => null, 'pricing' => 'keep', '_property' => $t->a->property->id])->assertStatus($allowed ? 302 : 403);
})->with([[OrganizationRole::Owner, true], [OrganizationRole::GroupOperationsManager, true], [OrganizationRole::PropertyManager, true], [OrganizationRole::FrontDesk, true], [OrganizationRole::Finance, false], [OrganizationRole::Auditor, false], [OrganizationRole::Housekeeping, false]]);

test('amendment rejects another property payload and stale property intent', function () {
    $t = twoTenants();
    [$reservation, $details] = inTenant($t->a->property, function () use ($t) {
        $details = BookingForm::details($t->a->owner);

        return [app(CreateReservation::class)->handle($t->a->owner, $details), $details];
    });
    $foreign = inTenant($t->b->property, fn () => BookingForm::details($t->b->owner));
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner);
    $this->patchJson(route('reservations.amend', $reservation), [...$foreign, 'room_id' => null, 'pricing' => 'keep', '_property' => $t->a->property->id])->assertUnprocessable()->assertJsonValidationErrors(['room_type_id', 'rate_plan_id']);
    $this->patchJson(route('reservations.amend', $reservation), [...$details, 'room_id' => null, 'pricing' => 'keep', '_property' => $t->a->otherProperty->id])->assertNotFound();
});

test('failure recording an amendment lifecycle event rolls back every reservation effect', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        $reservation = app(CreateReservation::class)->handle($owner, $details);
        ReservationLifecycleEvent::creating(function ($event) {
            if ($event->getAttribute('type') === 'modified') {
                throw new RuntimeException('Event write failed');
            }
        });
        try {
            expect(fn () => app(AmendReservation::class)->handle($owner, $reservation, [...$details, 'arrival' => '2026-12-11', 'room_id' => null, 'pricing' => 'reprice']))->toThrow(RuntimeException::class, 'Event write failed');
            expect($reservation->fresh()->arrival->toDateString())->toBe('2026-12-10')
                ->and($reservation->priceLines()->pluck('amount')->all())->toBe([11000, 11000])
                ->and($reservation->lifecycleEvents()->count())->toBe(1)
                ->and(InventoryRecord::query()->whereBetween('date', ['2026-12-10', '2026-12-11'])->orderBy('date')->pluck('sold_count')->all())->toBe([1, 1]);
        } finally {
            ReservationLifecycleEvent::flushEventListeners();
        }
    });
});

test('amendment history paginates fifty events and retains query parameters', function () {
    $t = twoTenants();
    $reservation = inTenant($t->a->property, function () use ($t) {
        $reservation = app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
        for ($i = 0; $i < 51; $i++) {
            app(RecordLifecycleEvent::class)->handle($reservation, 'modified', $t->a->owner, ['before' => ['room_id' => null], 'after' => ['room_id' => null]]);
        }

        return $reservation;
    });
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)->get(route('reservations.show', [$reservation, 'amendments_page' => 2, 'filter' => 'all']))->assertInertia(fn (Assert $page) => $page->has('amendments.data', 1)->where('amendments.total', 51)->where('amendments.current_page', 2)->where('amendments.prev_page_url', fn ($url) => str_contains($url, 'filter=all')));
});

test('standalone room assign change and clear appear with before and after in history', function () {
    $t = twoTenants();
    [$reservation, $first, $second] = inTenant($t->a->property, function () use ($t) {
        $details = BookingForm::details($t->a->owner);
        $first = Room::query()->sole();
        $second = app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($details['room_type_id'], ['number' => '102']));

        return [app(CreateReservation::class)->handle($t->a->owner, $details), $first, $second];
    });
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner);
    $before = null;
    foreach ([$first->id, $second->id, null] as $index => $roomId) {
        $this->patch(route('reservations.room-assignment', $reservation), ['_property' => $t->a->property->id, 'room_id' => $roomId])->assertSessionHasNoErrors();
        $this->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page->has('amendments.data', $index + 1)->where('amendments.data.'.$index.'.snapshot.before.room_id', $before)->where('amendments.data.'.$index.'.snapshot.after.room_id', $roomId)->where('amendments.data.'.$index.'.actor.name', $t->a->owner->name));
        $before = $roomId;
    }
});

test('amendment preview lists every broken restriction even with a reason already entered', function () {
    $t = twoTenants();
    [$reservation, $details] = inTenant($t->a->property, function () use ($t) {
        $details = BookingForm::details($t->a->owner);
        $reservation = app(CreateReservation::class)->handle($t->a->owner, $details);
        RateRestriction::query()->create(['rate_plan_id' => $details['rate_plan_id'], 'room_type_id' => $details['room_type_id'], 'date' => $details['arrival'], 'min_stay' => 3, 'closed_to_arrival' => true, 'stop_sell' => true]);

        return [$reservation, $details];
    });
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)->getJson(route('reservations.amend-preview', [$reservation, ...$details, 'room_id' => '', 'pricing' => 'keep', 'restriction_override_reason' => 'Returning guest']))->assertOk()->assertExactJson(['warnings' => ['Minimum stay on 2026-12-10 is 3 nights; this stay is 2 nights.', 'Closed to arrival on 2026-12-10.', 'Stop-sell on 2026-12-10.']]);
    expect(inTenant($t->a->property, fn () => $reservation->lifecycleEvents()->count()))->toBe(1);
});

test('amendment never creates or requotes a missing past price line', function (string $pricing) {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner, $pricing, $property) {
        $details = BookingForm::details($owner);
        $reservation = app(CreateReservation::class)->handle($owner, $details);
        $property->update(['business_date' => '2026-12-11']);
        $reservation->priceLines()->whereDate('date', '2026-12-10')->delete();
        RatePrice::query()->whereDate('date', '2026-12-10')->delete();
        $amended = app(AmendReservation::class)->handle($owner, $reservation, [...$details, 'room_id' => null, 'pricing' => $pricing]);
        expect($amended->priceLines->pluck('date')->map->toDateString()->all())->toBe(['2026-12-11']);
    });
})->with(['keep', 'reprice']);

test('moving arrival into the property local past is refused without changing the stay', function () {
    $t = twoTenants();
    [$reservation, $details] = inTenant($t->a->property, function () use ($t) {
        $details = BookingForm::details($t->a->owner);
        $reservation = app(CreateReservation::class)->handle($t->a->owner, [...$details, 'arrival' => '2026-12-11']);

        return [$reservation, $details];
    });
    $t->a->property->update(['business_date' => '2026-12-11']);
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)->patchJson(route('reservations.amend', $reservation), [...$details, 'room_id' => null, 'pricing' => 'reprice', '_property' => $t->a->property->id])->assertUnprocessable()->assertJsonValidationErrors('arrival');
    inTenant($t->a->property, function () use ($reservation) {
        expect($reservation->fresh()->arrival->toDateString())->toBe('2026-12-11')->and($reservation->priceLines()->count())->toBe(1)->and($reservation->lifecycleEvents()->count())->toBe(1);
    });
});
