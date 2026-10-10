<?php

use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Rates\Actions\SetRatePrices;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\ReservationLifecycleEvent;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\BookingForm;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(DatabaseMigrations::class);

// DatabaseMigrations commits fixtures; these tests deliberately have no RefreshDatabase transaction.
test('parallel MySQL amendments compete for the last room with exactly one success', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Real booking concurrency requires MySQL.');
    }
    [$owner, , $property] = organizationWithOwner();
    [$details, $ids] = inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        $first = app(CreateReservation::class)->handle($owner, [...$details, 'departure' => '2026-12-11']);
        $second = app(CreateReservation::class)->handle($owner, [...$details, 'arrival' => '2026-12-11']);
        app(SetRatePrices::class)->handle($owner, RatePlan::query()->sole(), ['room_type_id' => $details['room_type_id'], 'from' => '2026-12-12', 'to' => '2026-12-12', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'base_price' => 20000, 'extra_adult' => 0, 'extra_child' => 0]);

        return [[...$details, 'arrival' => '2026-12-12', 'departure' => '2026-12-13', 'room_id' => null, 'pricing' => 'reprice'], [$first->id, $second->id]];
    });
    $directory = sys_get_temp_dir().'/booking-'.bin2hex(random_bytes(8));
    mkdir($directory);
    $workers = [];
    try {
        for ($i = 0; $i < 2; $i++) {
            $input = json_encode(['connection' => config('database.connections.mysql'), 'organization_id' => $property->organization_id, 'property_id' => $property->id, 'actor_id' => $owner->id, 'reservation_id' => $ids[$i], 'details' => $details], JSON_THROW_ON_ERROR);
            $worker = new Process([PHP_BINARY, '-d', 'memory_limit=1G', base_path('tests/Support/amendment-worker.php'), $input, "$directory/ready-$i", "$directory/start"], base_path(), timeout: 30);
            $worker->start();
            $workers[] = $worker;
        }
        $deadline = microtime(true) + 15;
        while (! file_exists("$directory/ready-0") || ! file_exists("$directory/ready-1")) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Workers failed to reach booking barrier.');
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
        inTenant($property, function () {
            expect(Reservation::query()->whereDate('arrival', '2026-12-12')->count())->toBe(1)
                ->and(ReservationLifecycleEvent::query()->where('type', 'modified')->count())->toBe(1)
                ->and(InventoryRecord::query()->whereDate('date', '2026-12-12')->sole()->sold_count)->toBe(1)
                ->and((int) InventoryRecord::query()->sum('sold_count'))->toBe(2);
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
