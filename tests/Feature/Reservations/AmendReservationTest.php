<?php

use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Inventory\Models\Room;
use App\Domains\Rates\Actions\SetRatePrices;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Rates\Models\RatePrice;
use App\Domains\Reservations\Actions\AmendReservation;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\RoomAssignmentNight;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;

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
        ->has('amendments', 1)
        ->where('amendments.0.actor.name', $actor->name)
        ->where('amendments.0.snapshot.before.children', 1)
        ->where('amendments.0.snapshot.after.children', 0));
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
        expect($amended->lifecycleEvents()->latest('sequence')->first()->snapshot['price_lines'])->toHaveCount(3);
    });
});

test('re-pricing preserves past lines and replaces only future prices in property local time', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['timezone' => 'Africa/Dar_es_Salaam']);
    inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        $reservation = app(CreateReservation::class)->handle($owner, $details);
        $this->travelTo(CarbonImmutable::parse('2026-12-10 22:00:00 UTC'));
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
