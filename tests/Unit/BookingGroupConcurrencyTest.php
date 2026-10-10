<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\ReservationNumberSequence;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;
use Tests\Support\ParallelBookings;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(DatabaseMigrations::class);

// DatabaseMigrations commits fixtures; these tests deliberately have no RefreshDatabase transaction.
test('parallel MySQL booking groups take all rooms or none', function (int $rooms, int $successes) {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Real booking concurrency requires MySQL.');
    }
    [$owner, , $property] = organizationWithOwner();
    $details = inTenant($property, function () use ($owner, $rooms) {
        $details = BookingForm::details($owner);
        for ($room = 2; $room <= $rooms; $room++) {
            app(CreateRoom::class)->handle($owner, InventoryForm::room($details['room_type_id'], ['number' => (string) (100 + $room)]));
        }

        return [...$details, 'rooms' => 2];
    });
    $input = json_encode(['connection' => config('database.connections.mysql'), 'organization_id' => $property->organization_id, 'property_id' => $property->id, 'actor_id' => $owner->id, 'details' => $details], JSON_THROW_ON_ERROR);
    $results = ParallelBookings::run($input);
    $numbers = array_column($results, 'number');
    expect($numbers)->toHaveCount($successes)->and(array_unique($numbers))->toHaveCount($successes)
        ->and(array_column($results, 'refused'))->toHaveCount(2 - $successes);
    inTenant($property, function () use ($successes) {
        expect(Reservation::query()->count())->toBe($successes * 2)
            ->and(ReservationNumberSequence::query()->sole()->last_number)->toBe($successes * 2)
            ->and(InventoryRecord::query()->whereBetween('date', ['2026-12-10', '2026-12-11'])->pluck('sold_count')->all())->toBe([$successes * 2, $successes * 2]);
    });
})->with([[2, 1], [3, 1], [4, 2]]);
