<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Inventory\Models\Room;
use App\Domains\Rates\Models\RatePrice;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\ReservationLifecycleEvent;
use App\Domains\Reservations\Models\ReservationNumberSequence;
use App\Domains\Reservations\Models\ReservationPriceLine;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;

test('new booking opens without parameters using business date and property scoped choices', function () {
    $t = twoTenants();
    $details = inTenant($t->a->property, fn () => bookingDetails($t->a->owner));
    inTenant($t->a->otherProperty, fn () => bookingDetails($t->a->owner));
    inTenant($t->b->property, fn () => bookingDetails($t->b->owner));
    $t->a->property->update(['business_date' => '2026-12-10']);
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))
        ->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->get(route('reservations.create'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('reservations/create')->where('stay.arrival', '2026-12-10')->where('stay.departure', '2026-12-11')
        ->where('stay.adults', 1)->where('stay.children', 0)->where('roomType', null)
        ->where('stay.rate_plan_id', $details['rate_plan_id'])->has('ratePlans', 1)->has('rooms', 1)
        ->where('rooms.0.room_type_id', $details['room_type_id'])->where('countries.0.code', 'TZ')
        ->where('countries', fn ($countries) => count($countries) >= 249 && collect($countries)->contains(fn ($country) => $country['code'] === 'GB' && $country['name'] === 'United Kingdom')));
});

test('booking refresh derives room type and price from the selected room', function () {
    $t = twoTenants();
    $details = inTenant($t->a->property, fn () => bookingDetails($t->a->owner));
    $room = inTenant($t->a->property, fn () => Room::query()->sole());
    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->get(route('reservations.create', [
        'room_id' => $room->id, 'arrival' => $details['arrival'], 'departure' => $details['departure'], 'adults' => 2, 'children' => 1,
    ]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('stay.room_type_id', $details['room_type_id'])->where('ratePlan.id', $details['rate_plan_id'])
        ->where('nightlyRate', 11000)->has('rooms', 1));
    $payload = [...$details, 'room_id' => $room->id, '_property' => $t->a->property->id];
    unset($payload['room_type_id']);
    $this->post(route('reservations.store'), $payload)->assertSessionHasNoErrors()->assertRedirect();
    $this->get(route('reservations.create', ['arrival' => $details['arrival'], 'departure' => $details['departure']]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('rooms', 0));
});

test('direct booking offers free rooms across types and handles a property without rate plans', function () {
    $t = twoTenants();
    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->get(route('reservations.create'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('ratePlan', null)->has('rooms', 0));
    inTenant($t->a->property, function () use ($t) {
        bookingDetails($t->a->owner);
        $type = app(CreateRoomType::class)->handle($t->a->owner, [...InventoryForm::roomType(), 'name' => 'Suite', 'code' => 'SUITE']);
        app(CreateRoom::class)->handle($t->a->owner, [...InventoryForm::room($type->id), 'number' => '202']);
    });
    $this->get(route('reservations.create'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('rooms', 2));
    $this->getJson(route('reservations.create', ['arrival' => 'bad-date']))->assertUnprocessable()->assertJsonValidationErrors('arrival');
    $this->postJson(route('reservations.store'), ['arrival' => '2026-12-10', 'departure' => '2026-12-11', 'adults' => 1, 'children' => 0, 'source' => 'direct', 'lead_guest' => ['first_name' => 'A', 'last_name' => 'Guest'], '_property' => $t->a->property->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['room_type_id', 'rate_plan_id']);
});

test('front desk books an availability result and opens its reservation detail', function () {
    $t = twoTenants();
    $details = inTenant($t->a->property, fn () => bookingDetails($t->a->owner));
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));
    $this->get(route('reservations.create', $details))->assertOk()->assertInertia(fn (Assert $page) => $page->component('reservations/create')->where('stay.room_type_id', $details['room_type_id']));
    $response = $this->post(route('reservations.store'), [...$details, '_property' => $t->a->property->id]);
    $response->assertRedirect();
    $this->get($response->headers->get('Location'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('reservations/show')->where('reservation.status', 'confirmed')->where('booker.name', 'Travel Booker')->where('reservation.meal_plan', 'room-only')->where('leadGuest', null)->has('reservation.price_lines', 2));
});

test('booking refuses an exhausted night without selling any earlier night', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $details = bookingDetails($owner);
        app(CreateReservation::class)->handle($owner, [...$details, 'arrival' => '2026-12-11']);
        expect(fn () => app(CreateReservation::class)->handle($owner, $details))->toThrow(ValidationException::class);
        expect(InventoryRecord::query()->where('room_type_id', $details['room_type_id'])->whereDate('date', '2026-12-10')->sole()->sold_count)->toBe(0)
            ->and(Reservation::query()->count())->toBe(1)
            ->and(ReservationLifecycleEvent::query()->count())->toBe(1);
    });
});

test('a failed outer transaction rolls back inventory number prices and lifecycle event', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $details = bookingDetails($owner);
        try {
            DB::transaction(function () use ($owner, $details) {
                app(CreateReservation::class)->handle($owner, $details);
                throw new RuntimeException('abort booking');
            });
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toBe('abort booking');
        }
        expect(Reservation::query()->count())->toBe(0)
            ->and(ReservationPriceLine::query()->count())->toBe(0)
            ->and(ReservationLifecycleEvent::query()->count())->toBe(0)
            ->and(ReservationNumberSequence::query()->count())->toBe(0)
            ->and(InventoryRecord::query()->sum('sold_count'))->toBe(0);
        $first = app(CreateReservation::class)->handle($owner, [...$details, 'departure' => '2026-12-11']);
        $first->update(['status' => ReservationStatus::Cancelled]);
        $second = app(CreateReservation::class)->handle($owner, [...$details, 'arrival' => '2026-12-11']);
        expect($first->reservation_number)->toBe('RES-000001')
            ->and($second->reservation_number)->toBe('RES-000002');
    });
});

test('booking permission and detail access follow the role', function (OrganizationRole $role, bool $allowed) {
    $t = twoTenants();
    $details = inTenant($t->a->property, fn () => bookingDetails($t->a->owner));
    $reservation = inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, [...$details, 'departure' => '2026-12-11']));
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->member($role));
    $this->get(route('reservations.create', $details))->assertStatus($allowed ? 200 : 403);
    $this->get(route('reservations.show', $reservation))->assertStatus($allowed ? 200 : 403);
    $this->post(route('reservations.store'), [...$details, 'arrival' => '2026-12-11', '_property' => $t->a->property->id])->assertStatus($allowed ? 302 : 403);
})->with([
    [OrganizationRole::Owner, true], [OrganizationRole::GroupOperationsManager, true],
    [OrganizationRole::PropertyManager, true], [OrganizationRole::FrontDesk, true],
    [OrganizationRole::Housekeeping, false],
]);

test('reservation numbers and foreign inputs are isolated between properties of one organization', function () {
    $t = twoTenants();
    $details = inTenant($t->a->otherProperty, fn () => bookingDetails($t->a->owner));
    $reservation = inTenant($t->a->otherProperty, fn () => app(CreateReservation::class)->handle($t->a->owner, $details));
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner);
    $this->get(route('reservations.show', $reservation->reservation_number))->assertNotFound();
    $this->postJson(route('reservations.store'), [...$details, '_property' => $t->a->property->id])->assertUnprocessable()->assertJsonValidationErrors(['room_type_id', 'rate_plan_id', 'booker_id']);
});

test('nightly prices remain fixed after rate prices change', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $details = bookingDetails($owner);
        $reservation = app(CreateReservation::class)->handle($owner, $details);
        RatePrice::query()->where('rate_plan_id', $details['rate_plan_id'])->update(['base_price' => 99999]);
        expect($reservation->fresh()->priceLines->pluck('amount')->all())->toBe([11000, 11000]);
    });
});

function bookingDetails($owner): array
{
    return BookingForm::details($owner);
}

test('booking confirms one room with fixed nightly prices a property number and a created lifecycle event', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner, $property) {
        $details = bookingDetails($owner);
        $reservation = app(CreateReservation::class)->handle($owner, $details);
        expect($reservation->status)->toBe(ReservationStatus::Confirmed)
            ->and($reservation->reservation_number)->not->toBe($reservation->id)
            ->and($reservation->payment_collector)->toBe('property');
        expect($reservation->priceLines->pluck('amount')->all())->toBe([11000, 11000])
            ->and($reservation->priceLines->pluck('currency')->all())->toBe(['USD', 'USD']);
        $event = $reservation->lifecycleEvents->sole();
        expect($event->type)->toBe('created')->and($event->sequence)->toBe(1)
            ->and($event->actor_id)->toBe($owner->id)->and($event->occurred_at)->not->toBeNull()
            ->and($event->business_date->toDateString())->toBe(now($property->timezone)->toDateString())
            ->and($event->snapshot['reservation_number'])->toBe($reservation->reservation_number);
    });
});

test('new booking refuses an arrival before the property local date', function () {
    $t = twoTenants();
    $details = inTenant($t->a->property, fn () => BookingForm::details($t->a->owner));
    $t->a->property->update(['business_date' => '2026-12-11']);
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)->postJson(route('reservations.store'), [...$details, '_property' => $t->a->property->id])->assertUnprocessable()->assertJsonValidationErrors('arrival');
});

test('each property numbers its reservations from RES-000001 and opens only its own', function () {
    $t = twoTenants();
    $book = fn ($tenant, $property) => inTenant($property, fn () => app(CreateReservation::class)->handle($tenant->owner, bookingDetails($tenant->owner)));
    $a = $book($t->a, $t->a->property);
    $b = $book($t->b, $t->b->property);
    expect($a->reservation_number)->toBe('RES-000001')->and($b->reservation_number)->toBe('RES-000001');
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)
        ->get(route('reservations.show', 'RES-000001'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('reservation.id', $a->id));
});
