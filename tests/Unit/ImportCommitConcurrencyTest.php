<?php

use App\Domains\Guests\Models\Booker;
use App\Domains\Guests\Models\Guest;
use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Onboarding\Actions\DryRunImport;
use App\Domains\Onboarding\Models\ImportJob;
use App\Domains\Reservations\Models\Reservation;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BookingForm;
use Tests\Support\ImportForm;
use Tests\Support\ParallelBookings;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(DatabaseMigrations::class);

test('parallel MySQL import commits create one reservation and persist repeat-safe outcomes', function (bool $sameJob) {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Real import concurrency requires MySQL.');
    }
    Storage::fake('local');
    [$owner, , $property] = organizationWithOwner();
    $jobs = inTenant($property, function () use ($owner, $sameJob) {
        BookingForm::details($owner);
        $first = app(DryRunImport::class)->handle($owner, ImportForm::csv([ImportForm::row()]));
        $second = $sameJob ? $first : app(DryRunImport::class)->handle($owner, ImportForm::csv([ImportForm::row()]));

        return [$first->id, $second->id];
    });
    $input = json_encode([
        'connection' => config('database.connections.mysql'), 'organization_id' => $property->organization_id,
        'property_id' => $property->id, 'actor_id' => $owner->id, 'job_ids' => $jobs,
    ], JSON_THROW_ON_ERROR);
    $results = ParallelBookings::run($input, 'import-commit-worker.php');
    expect(array_unique(array_column($results, 'reservation_number')))->toHaveCount(1);
    if ($sameJob) {
        expect(array_column($results, 'status'))->toBe(['created', 'created']);
    } else {
        expect(array_column($results, 'status'))->toContain('created', 'duplicate');
    }
    inTenant($property, function () {
        expect(Reservation::query()->count())->toBe(1)
            ->and(Guest::query()->count())->toBe(1)
            ->and(Booker::query()->count())->toBe(2)
            ->and(ImportJob::query()->where('status', '!=', 'committed')->count())->toBe(0)
            ->and(InventoryRecord::query()->whereBetween('date', ['2026-12-10', '2026-12-11'])->pluck('sold_count')->all())->toBe([1, 1]);
    });
})->with([true, false]);
