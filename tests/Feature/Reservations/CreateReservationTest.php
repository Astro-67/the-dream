<?php

use App\Domains\Guests\Actions\CreateBooker;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Rates\Actions\CreateRatePlan;
use App\Domains\Rates\Actions\SetRatePrices;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use Tests\Support\InventoryForm;
use Inertia\Testing\AssertableInertia as Assert;
use App\Domains\Tenancy\Enums\OrganizationRole;

test('front desk books an availability result and opens its reservation detail', function () {
    $t = twoTenants();
    $details = inTenant($t->a->property, fn () => bookingDetails($t->a->owner));
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));
    $this->get(route('reservations.create', $details))->assertOk()->assertInertia(fn (Assert $page) => $page->component('reservations/create')->where('stay.room_type_id', $details['room_type_id']));
    $response = $this->post(route('reservations.store'), [...$details, '_property' => $t->a->property->id]);
    $response->assertRedirect();
    $this->get($response->headers->get('Location'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('reservations/show')->where('reservation.status', 'confirmed')->where('booker.name', 'Travel Booker')->has('reservation.price_lines', 2));
});

test('booking refuses an exhausted night without selling any earlier night', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $details = bookingDetails($owner);
        app(CreateReservation::class)->handle($owner, [...$details, 'arrival' => '2026-12-11']);
        expect(fn () => app(CreateReservation::class)->handle($owner, $details))->toThrow(\Illuminate\Validation\ValidationException::class);
        expect(\App\Domains\Inventory\Models\InventoryRecord::query()->where('room_type_id', $details['room_type_id'])->whereDate('date', '2026-12-10')->sole()->sold_count)->toBe(0)
            ->and(\App\Domains\Reservations\Models\Reservation::query()->count())->toBe(1)
            ->and(\App\Domains\Reservations\Models\ReservationLifecycleEvent::query()->count())->toBe(1);
    });
});

test('a failed outer transaction rolls back inventory number prices and lifecycle event', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner, $property) {
        $details = bookingDetails($owner);
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($owner, $details) {
                app(CreateReservation::class)->handle($owner, $details);
                throw new RuntimeException('abort booking');
            });
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toBe('abort booking');
        }
        expect(\App\Domains\Reservations\Models\Reservation::query()->count())->toBe(0)
            ->and(\App\Domains\Reservations\Models\ReservationPriceLine::query()->count())->toBe(0)
            ->and(\App\Domains\Reservations\Models\ReservationLifecycleEvent::query()->count())->toBe(0)
            ->and(\App\Domains\Reservations\Models\ReservationNumberSequence::query()->count())->toBe(0)
            ->and(\App\Domains\Inventory\Models\InventoryRecord::query()->sum('sold_count'))->toBe(0);
        $first = app(CreateReservation::class)->handle($owner, [...$details, 'departure' => '2026-12-11']);
        $first->update(['status' => ReservationStatus::Cancelled]);
        $second = app(CreateReservation::class)->handle($owner, [...$details, 'arrival' => '2026-12-11']);
        expect($first->reservation_number)->toBe($property->id.'-000001')
            ->and($second->reservation_number)->toBe($property->id.'-000002');
    });
});

function bookingDetails($owner): array
{
    $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType());
    app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id));
    $plan = app(CreateRatePlan::class)->handle($owner, ['name' => 'Breakfast', 'code' => 'BB', 'currency' => 'USD', 'room_type_ids' => [$type->id]]);
    app(SetRatePrices::class)->handle($owner, $plan, ['room_type_id' => $type->id, 'from' => '2026-12-10', 'to' => '2026-12-11', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'base_price' => 10000, 'extra_adult' => 2500, 'extra_child' => 1000]);
    $booker = app(CreateBooker::class)->handle($owner, ['name' => 'Travel Booker']);

    return ['room_type_id' => $type->id, 'rate_plan_id' => $plan->id, 'booker_id' => $booker->id, 'arrival' => '2026-12-10', 'departure' => '2026-12-12', 'adults' => 2, 'children' => 1, 'source' => 'phone'];
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
