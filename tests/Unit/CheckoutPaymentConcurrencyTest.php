<?php

use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Models\Folio;
use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\BookingForm;
use Tests\Support\ParallelBookings;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(DatabaseMigrations::class);

test('check-out rechecks a payment committed after its transaction snapshot began', function (string $method) {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Payment/check-out isolation requires MySQL.');
    }
    $t = twoTenants();
    $property = $t->a->property;
    $property->update(['business_date' => '2026-12-10']);
    [$reservation, $folio] = inTenant($property, function () use ($t) {
        $reservation = app(CreateReservation::class)->handle($t->a->owner, [
            ...BookingForm::details($t->a->owner), 'room_id' => Room::query()->sole()->id,
            'payment_collector' => 'channel', 'channel_collected_amount' => 1500,
        ]);
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        $guest = app(CreateGuest::class)->handle($t->a->owner, [
            'name' => 'Concurrent departure', 'documents' => [['type' => 'passport', 'number' => 'TEST-CONCURRENT', 'nationality' => 'DE']],
        ]);
        ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);
        $reservation = app(CheckIn::class)->handle($t->a->owner, $reservation);
        $folio = Folio::query()->where('reservation_id', $reservation->id)->sole();
        app(PostExtraCharge::class)->handle($t->a->owner, $folio, ['category' => 'laundry', 'quantity' => 1, 'unit_price' => 1500]);

        return [$reservation, $folio];
    });
    $property->update(['business_date' => '2026-12-12']);
    $actor = $t->a->member(OrganizationRole::FrontDesk);

    $results = ParallelBookings::run(json_encode([
        'connection' => config('database.connections.mysql'), 'organization_id' => $t->a->organization->id,
        'property_id' => $property->id, 'actor_id' => $actor->id,
        'reservation_id' => $reservation->id, 'folio_id' => $folio->id, 'method' => $method,
    ], JSON_THROW_ON_ERROR), 'checkout-payment-worker.php');

    expect($results)->toEqual([['paid' => true], ['status' => 'checked_out']]);
    inTenant($property, function () use ($reservation, $folio) {
        expect($reservation->fresh()->status)->toBe(ReservationStatus::CheckedOut)
            ->and($folio->balance()->amount)->toBe(0)
            ->and($reservation->lifecycleEvents()->where('type', 'checked_out')->count())->toBe(1);
    });
})->with(['bank_transfer', 'channel_collected']);
