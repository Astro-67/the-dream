<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Actions\TakeRoomOutOfService;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Actions\CreateRatePlan;
use App\Domains\Rates\Actions\DeactivateRatePlan;
use App\Domains\Rates\Actions\SetRatePrices;
use App\Domains\Rates\Actions\SetRateRestrictions;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;

beforeEach(function () {
    $this->travelTo(now()->setTimezone('Africa/Dar_es_Salaam')->setDate(2026, 10, 8)->setTime(9, 0));
    config(['inventory.horizon_years' => 1]);
});

/**
 * Deluxe has two rooms, one out of service 10-11 Oct. Breakfast prices and
 * restricts Deluxe; Non-refundable derives from it; Old Rate is deactivated.
 *
 * @return array{deluxe: RoomType, garden: RoomType, base: RatePlan, derived: RatePlan}
 */
function calendarSetup(Property $property, User $owner): array
{
    return inTenant($property, function () use ($owner) {
        $deluxe = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType());
        $garden = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType(['name' => 'Garden Twin', 'code' => 'GDN']));
        $room = app(CreateRoom::class)->handle($owner, InventoryForm::room($deluxe->id));
        app(CreateRoom::class)->handle($owner, InventoryForm::room($deluxe->id, ['number' => '102']));
        app(TakeRoomOutOfService::class)->handle($owner, $room, ['starts_on' => '2026-10-10', 'returns_on' => '2026-10-12', 'reason' => 'Leak']);
        $base = app(CreateRatePlan::class)->handle($owner, ['name' => 'Breakfast', 'code' => 'BB', 'currency' => 'USD', 'room_type_ids' => [$deluxe->id, $garden->id]]);
        app(SetRatePrices::class)->handle($owner, $base, ['room_type_id' => $deluxe->id, 'from' => '2026-10-09', 'to' => '2026-10-10', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'base_price' => 10000, 'extra_adult' => 0, 'extra_child' => 0]);
        app(SetRateRestrictions::class)->handle($owner, $base, ['room_type_id' => $deluxe->id, 'from' => '2026-10-10', 'to' => '2026-10-10', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'min_stay' => 2, 'closed_to_arrival' => true, 'stop_sell' => false]);
        $derived = app(CreateRatePlan::class)->handle($owner, ['name' => 'Non-refundable', 'code' => 'NR', 'base_rate_plan_id' => $base->id, 'adjustment_kind' => 'percent', 'adjustment' => -10]);
        $old = app(CreateRatePlan::class)->handle($owner, ['name' => 'Old Rate', 'code' => 'OLD', 'currency' => 'USD', 'room_type_ids' => [$deluxe->id]]);
        app(DeactivateRatePlan::class)->handle($owner, $old);

        return ['deluxe' => $deluxe, 'garden' => $garden, 'base' => $base, 'derived' => $derived];
    });
}

test('the calendar shows sellable, available, out of service, every active plan\'s prices and restrictions for the chosen dates and room types', function () {
    $t = twoTenants();
    $s = calendarSetup($t->a->property, $t->a->owner);

    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))
        ->get(route('rate-calendar', ['from' => '2026-10-09', 'to' => '2026-10-11', 'room_type_ids' => [$s['deluxe']->id]]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('rates/calendar')
        ->where('from', '2026-10-09')->where('to', '2026-10-11')
        ->where('dates', ['2026-10-09', '2026-10-10', '2026-10-11'])
        ->where('canManage', true)
        ->has('allRoomTypes', 2)
        ->has('roomTypes', 1)
        ->where('roomTypes.0.id', $s['deluxe']->id)
        ->where('roomTypes.0.sellable', ['2026-10-09' => 2, '2026-10-10' => 1, '2026-10-11' => 1])
        ->where('roomTypes.0.available', ['2026-10-09' => 2, '2026-10-10' => 1, '2026-10-11' => 1])
        ->where('roomTypes.0.outOfService', ['2026-10-09' => 0, '2026-10-10' => 1, '2026-10-11' => 1])
        ->has('roomTypes.0.plans', 2)
        ->where('roomTypes.0.plans.0.code', 'BB')->where('roomTypes.0.plans.0.derived', false)->where('roomTypes.0.plans.0.currency', 'USD')
        ->where('roomTypes.0.plans.0.prices', ['2026-10-09' => 10000, '2026-10-10' => 10000, '2026-10-11' => null])
        ->where('roomTypes.0.plans.0.restrictions', ['2026-10-09' => null, '2026-10-10' => ['min_stay' => 2, 'closed_to_arrival' => true, 'stop_sell' => false], '2026-10-11' => null])
        ->where('roomTypes.0.plans.1.code', 'NR')->where('roomTypes.0.plans.1.derived', true)
        ->where('roomTypes.0.plans.1.prices', ['2026-10-09' => 9000, '2026-10-10' => 9000, '2026-10-11' => null])
        ->where('roomTypes.0.plans.1.restrictions', ['2026-10-09' => null, '2026-10-10' => null, '2026-10-11' => null]));

    $this->get(route('rate-calendar', ['from' => '2026-10-09', 'to' => '2026-10-11', 'room_type_ids' => [$s['garden']->id]]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('roomTypes.0.sellable', ['2026-10-09' => 0, '2026-10-10' => 0, '2026-10-11' => 0])
        ->where('roomTypes.0.available', ['2026-10-09' => 0, '2026-10-10' => 0, '2026-10-11' => 0]));
});

test('a rates manager takes a room out of order from the calendar with its reason and nightly counts', function () {
    $t = twoTenants();
    $s = calendarSetup($t->a->property, $t->a->owner);
    $calendar = route('rate-calendar', ['from' => '2026-10-08', 'to' => '2026-10-12', 'room_type_ids' => [$s['deluxe']->id]]);
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->get($calendar)
        ->assertInertia(fn (Assert $page) => $page->where('roomTypes.0.rooms.1.canManageOutOfOrder', true));
    $room = inTenant($t->a->property, fn () => $s['deluxe']->rooms()->where('number', '102')->sole());

    $this->from($calendar)->post(route('out-of-service.store', $room), [
        '_property' => $t->a->property->id, 'calendar' => true,
        'starts_on' => '2026-10-08', 'returns_on' => '2026-10-10', 'reason' => 'Leaking shower',
    ])->assertSessionHasNoErrors()->assertRedirect($calendar);

    $this->get($calendar)->assertInertia(fn (Assert $page) => $page
        ->where('roomTypes.0.rooms.1.outOfServicePeriods.0.startsOn', '2026-10-08')
        ->where('roomTypes.0.rooms.1.outOfServicePeriods.0.returnsOn', '2026-10-10')
        ->where('roomTypes.0.rooms.1.outOfServicePeriods.0.reason', 'Leaking shower')
        ->where('roomTypes.0.sellable', ['2026-10-08' => 1, '2026-10-09' => 1, '2026-10-10' => 1, '2026-10-11' => 1, '2026-10-12' => 2])
        ->where('roomTypes.0.available', ['2026-10-08' => 1, '2026-10-09' => 1, '2026-10-10' => 1, '2026-10-11' => 1, '2026-10-12' => 2]));
});

test('available rooms fall when booked and return when cancelled without changing sellable rooms', function () {
    $t = twoTenants();
    $reservation = inTenant($t->a->property, function () use ($t) {
        $details = BookingForm::details($t->a->owner);
        app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($details['room_type_id'], ['number' => '102']));
        app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($details['room_type_id'], ['number' => '103']));

        return app(CreateReservation::class)->handle($t->a->owner, $details);
    });
    $calendar = route('rate-calendar', ['from' => '2026-12-09', 'to' => '2026-12-12']);

    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get($calendar)
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('roomTypes.0.sellable', ['2026-12-09' => 3, '2026-12-10' => 3, '2026-12-11' => 3, '2026-12-12' => 3])
        ->where('roomTypes.0.available', ['2026-12-09' => 3, '2026-12-10' => 2, '2026-12-11' => 2, '2026-12-12' => 3]));

    $this->get($calendar.'&search=NoMatchingGuest')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('roomTypes.0.available', ['2026-12-09' => 3, '2026-12-10' => 2, '2026-12-11' => 2, '2026-12-12' => 3]));

    $this->post(route('reservations.cancel', $reservation), ['_property' => $t->a->property->id, 'reason' => 'Guest changed plans'])
        ->assertSessionHasNoErrors()->assertRedirect(route('reservations.show', $reservation));

    $this->get($calendar)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('roomTypes.0.sellable', ['2026-12-09' => 3, '2026-12-10' => 3, '2026-12-11' => 3, '2026-12-12' => 3])
        ->where('roomTypes.0.available', ['2026-12-09' => 3, '2026-12-10' => 3, '2026-12-11' => 3, '2026-12-12' => 3]));
});

test('a rates manager bulk edits prices and restrictions from the calendar, audited like the rate plan pages', function () {
    $t = twoTenants();
    $s = calendarSetup($t->a->property, $t->a->owner);
    $calendar = route('rate-calendar', ['from' => '2026-10-09', 'to' => '2026-10-11']);
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->get($calendar);

    $this->put(route('rate-calendar.prices', $s['base']), [
        '_property' => $t->a->property->id, 'room_type_id' => $s['garden']->id, 'from' => '2026-10-09', 'to' => '2026-10-11', 'weekdays' => [5, 6],
        'base_price' => 8000, 'extra_adult' => 1000, 'extra_child' => 500,
    ])->assertSessionHasNoErrors()->assertRedirect($calendar);
    $this->put(route('rate-calendar.restrictions', $s['derived']), [
        '_property' => $t->a->property->id, 'room_type_id' => $s['garden']->id, 'from' => '2026-10-11', 'to' => '2026-10-11', 'weekdays' => [7],
        'min_stay' => 3, 'stop_sell' => '1',
    ])->assertSessionHasNoErrors()->assertRedirect($calendar);

    $this->get($calendar)->assertInertia(fn (Assert $page) => $page
        ->where('roomTypes.1.plans.0.prices', ['2026-10-09' => 8000, '2026-10-10' => 8000, '2026-10-11' => null])
        ->where('roomTypes.1.plans.1.prices', ['2026-10-09' => 7200, '2026-10-10' => 7200, '2026-10-11' => null])
        ->where('roomTypes.1.plans.1.restrictions.2026-10-11', ['min_stay' => 3, 'closed_to_arrival' => false, 'stop_sell' => true]));
    $prices = Activity::query()->where('description', 'Rate prices updated')->latest('id')->first();
    expect($prices->getExtraProperty('attributes'))->toEqual(['base_price' => 8000, 'extra_adult' => 1000, 'extra_child' => 500])
        ->and($prices->getExtraProperty('weekdays'))->toBe([5, 6])->and($prices->getExtraProperty('room_type_id'))->toBe($s['garden']->id);
    $restrictions = Activity::query()->where('description', 'Restrictions updated')->latest('id')->first();
    expect($restrictions->getExtraProperty('attributes'))->toEqual(['min_stay' => 3, 'closed_to_arrival' => false, 'stop_sell' => true]);

    // A derived plan's prices follow its base plan.
    $this->put(route('rate-calendar.prices', $s['derived']), [
        '_property' => $t->a->property->id, 'room_type_id' => $s['garden']->id, 'from' => '2026-10-09', 'to' => '2026-10-09', 'weekdays' => [5],
        'base_price' => 1, 'extra_adult' => 0, 'extra_child' => 0,
    ])->assertSessionHasErrors('room_type_id');
});

test('front desk sees the calendar read-only and the server refuses their edits', function (OrganizationRole $role) {
    $t = twoTenants();
    $s = calendarSetup($t->a->property, $t->a->owner);
    $this->actingAs($t->a->member($role))->get(route('rate-calendar'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canManage', false)->has('dates', 15)->where('from', '2026-10-08')->has('roomTypes', 2));

    $this->put(route('rate-calendar.prices', $s['base']), ['_property' => $t->a->property->id, 'room_type_id' => $s['deluxe']->id, 'from' => '2026-10-09', 'to' => '2026-10-09', 'weekdays' => [5], 'base_price' => 1, 'extra_adult' => 0, 'extra_child' => 0])->assertForbidden();
    $this->put(route('rate-calendar.restrictions', $s['base']), ['_property' => $t->a->property->id, 'room_type_id' => $s['deluxe']->id, 'from' => '2026-10-09', 'to' => '2026-10-09', 'weekdays' => [5], 'min_stay' => 9])->assertForbidden();
    expect(Activity::query()->whereIn('description', ['Rate prices updated', 'Restrictions updated'])->count())->toBe(2);
})->with([OrganizationRole::FrontDesk, OrganizationRole::Finance]);

test('the calendar refuses a range it cannot show', function () {
    $t = twoTenants();
    $this->actingAs($t->a->owner)->get(route('rate-calendar', ['from' => '2026-10-09', 'to' => '2026-10-08']))->assertSessionHasErrors('to');
    $this->get(route('rate-calendar', ['from' => '2026-10-09', 'to' => '2027-10-08']))->assertSessionHasErrors('to');
    $this->get(route('rate-calendar', ['from' => 'soon', 'to' => '2026-10-10']))->assertSessionHasErrors('from');
});

test('partial calendar ranges return validation errors rather than throwing', function () {
    $t = twoTenants();
    $this->actingAs($t->a->owner)->get(route('rate-calendar', ['from' => '2026-12-01']))->assertSessionHasErrors('to');
    $this->get(route('rate-calendar', ['from' => '2026-10-09']))->assertSessionHasErrors('to');
    $this->get(route('rate-calendar', ['to' => '2026-10-07']))->assertSessionHasErrors('from');
});
