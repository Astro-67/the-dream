<?php

use App\Domains\Guests\Actions\CreateBooker;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Rates\Actions\CreateRatePlan;
use App\Domains\Rates\Actions\SetRatePrices;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use Tests\Support\InventoryForm;

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
