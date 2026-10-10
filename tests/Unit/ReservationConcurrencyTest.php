<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\ReservationNumberSequence;
use App\Domains\Inventory\Models\InventoryRecord;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(DatabaseMigrations::class);

// DatabaseMigrations commits fixtures; these tests deliberately have no RefreshDatabase transaction.
test('parallel MySQL bookings never oversell and allocate unique property numbers', function (int $rooms, int $successes) {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Real booking concurrency requires MySQL.');
    }
    [$owner, , $property] = organizationWithOwner();
    $details = inTenant($property, function () use ($owner, $rooms) {
        $details = BookingForm::details($owner);
        if ($rooms === 2) {
            app(CreateRoom::class)->handle($owner, InventoryForm::room($details['room_type_id'], ['number' => '102']));
        }
        return $details;
    });
    $input = json_encode(['connection' => config('database.connections.mysql'), 'organization_id' => $property->organization_id, 'property_id' => $property->id, 'actor_id' => $owner->id, 'details' => $details], JSON_THROW_ON_ERROR);
    $directory = sys_get_temp_dir().'/booking-'.bin2hex(random_bytes(8));
    mkdir($directory);
    $workers = [];
    try {
        for ($i = 0; $i < 2; $i++) {
            $worker = new Process([PHP_BINARY, '-d', 'memory_limit=1G', base_path('tests/Support/booking-worker.php'), $input, "$directory/ready-$i", "$directory/start"], base_path(), timeout: 30);
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
        $numbers = array_column($results, 'number');
        expect($numbers)->toHaveCount($successes)->and(array_unique($numbers))->toHaveCount($successes)
            ->and(array_column($results, 'refused'))->toHaveCount(2 - $successes);
        inTenant($property, function () use ($successes) {
            expect(Reservation::query()->count())->toBe($successes)
                ->and(ReservationNumberSequence::query()->sole()->last_number)->toBe($successes)
                ->and(InventoryRecord::query()->whereBetween('date', ['2026-12-10', '2026-12-11'])->pluck('sold_count')->all())->toBe([$successes, $successes]);
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
})->with([[1, 1], [2, 2]]);
