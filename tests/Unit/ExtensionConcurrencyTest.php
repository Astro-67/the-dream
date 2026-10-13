<?php

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Inventory\Models\Room;
use App\Domains\Rates\Actions\SetRatePrices;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\BookingForm;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(DatabaseMigrations::class);

// DatabaseMigrations commits fixtures; these tests deliberately have no RefreshDatabase transaction.
test('a MySQL extension and a booking compete for the last room with exactly one success', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Real booking concurrency requires MySQL.');
    }
    $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam'));
    [$owner, , $property] = organizationWithOwner();
    [$details, $inHouse] = inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        app(SetRatePrices::class)->handle($owner, RatePlan::query()->sole(), ['room_type_id' => $details['room_type_id'], 'from' => '2026-12-12', 'to' => '2026-12-12', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'base_price' => 20000, 'extra_adult' => 0, 'extra_child' => 0]);
        $reservation = app(CreateReservation::class)->handle($owner, [...$details, 'room_id' => Room::query()->sole()->id]);
        // The check-in itself is covered elsewhere; only the in-house status matters here.
        $reservation->update(['status' => ReservationStatus::CheckedIn]);
        RoomStatus::query()->update(['status' => HousekeepingStatus::Occupied]);

        return [[...$details, 'arrival' => '2026-12-12', 'departure' => '2026-12-13'], $reservation];
    });
    $base = ['connection' => config('database.connections.mysql'), 'organization_id' => $property->organization_id, 'property_id' => $property->id, 'actor_id' => $owner->id];
    $inputs = [[...$base, 'reservation_id' => $inHouse->id, 'departure' => '2026-12-13'], [...$base, 'details' => $details]];
    $directory = sys_get_temp_dir().'/extension-'.bin2hex(random_bytes(8));
    mkdir($directory);
    $workers = [];
    try {
        foreach ($inputs as $i => $input) {
            $worker = new Process([PHP_BINARY, '-d', 'memory_limit=1G', base_path('tests/Support/extension-worker.php'), json_encode($input, JSON_THROW_ON_ERROR), "$directory/ready-$i", "$directory/start"], base_path(), timeout: 30);
            $worker->start();
            $workers[] = $worker;
        }
        $deadline = microtime(true) + 15;
        while (! file_exists("$directory/ready-0") || ! file_exists("$directory/ready-1")) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Workers failed to reach extension barrier.');
            }
            usleep(10000);
        }
        touch("$directory/start");
        $results = [];
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
            try {
                $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException($worker->getOutput().$worker->getErrorOutput(), previous: $exception);
            }
        }
        expect(array_column($results, 'number'))->toHaveCount(1)
            ->and(array_column($results, 'refused'))->toHaveCount(1);
        inTenant($property, function () use ($inHouse) {
            $extended = $inHouse->fresh()->departure->toDateString() === '2026-12-13';
            expect(Reservation::query()->count())->toBe($extended ? 1 : 2)
                ->and($inHouse->lifecycleEvents()->where('type', 'extended')->count())->toBe((int) $extended)
                ->and(InventoryRecord::query()->whereDate('date', '2026-12-12')->sole()->sold_count)->toBe(1);
        });
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        foreach (glob("$directory/*") as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
});
