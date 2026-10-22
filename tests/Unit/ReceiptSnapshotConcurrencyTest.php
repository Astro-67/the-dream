<?php

use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Models\Folio;
use App\Domains\Reservations\Actions\CreateReservation;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\BookingForm;
use Tests\Support\ParallelBookings;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(DatabaseMigrations::class);

// protects=concurrent payments on one folio snapshot in the order they commit; fails_when=both snapshots miss each other.
test('two payments at once on one folio give snapshots that build on each other', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Real payment concurrency requires MySQL.');
    }
    [$owner, $organization, $property] = organizationWithOwner();
    $folio = inTenant($property, function () use ($owner) {
        app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
        $folio = Folio::query()->sole();
        app(PostExtraCharge::class)->handle($owner, $folio, ['category' => 'laundry', 'quantity' => 1, 'unit_price' => 5000]);

        return $folio;
    });

    $results = collect(ParallelBookings::run(json_encode([
        'connection' => config('database.connections.mysql'), 'organization_id' => $organization->id, 'property_id' => $property->id,
        'actor_id' => $owner->id, 'folio_id' => $folio->id, 'amounts' => [1000, 700],
    ], JSON_THROW_ON_ERROR), 'payment-worker.php'))->sortBy('sequence')->values();

    $first = $results[0]['summary']['net_payments'];
    expect($results->pluck('sequence')->all())->toBe([1, 2])
        ->and($first)->toBeIn([1000, 700])
        ->and($results[1]['summary']['net_payments'])->toBe(1700)
        ->and($results[1]['summary']['balance'])->toBe(3300)
        ->and($results[0]['summary']['balance'])->toBe(5000 - $first);
});
