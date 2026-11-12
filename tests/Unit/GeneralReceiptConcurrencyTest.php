<?php

use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BookingForm;
use Tests\Support\ParallelBookings;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(DatabaseMigrations::class);

// protects=two cashiers issuing at once put each extra on one receipt (spec #249); fails_when=both requests record a receipt; no_claim=ZRA delivery.
test('concurrent issues of one institution receipt produce one receipt', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('General receipt claim locking requires MySQL.');
    }
    Queue::fake();
    $t = twoTenants();
    [$reservation, $dinner] = inTenant($t->a->property, function () use ($t) {
        $reservation = app(CreateReservation::class)->handle($t->a->owner, [
            ...BookingForm::details($t->a->owner), 'adults' => 1, 'children' => 0,
            'paying_institution_type' => 'government', 'paying_institution_name' => 'Ministry of Health', 'paying_institution_zrb_number' => 'WA2400117',
        ]);
        $reservation->update(['status' => ReservationStatus::CheckedIn]);

        return [$reservation, app(PostExtraCharge::class)->handle($t->a->owner, Folio::query()->sole(), ['category' => 'restaurant', 'description' => 'DINNER', 'quantity' => 1, 'unit_price' => 1000])];
    });

    $results = ParallelBookings::run(json_encode([
        'connection' => config('database.connections.mysql'), 'organization_id' => $t->a->organization->id,
        'property_id' => $t->a->property->id, 'actor_id' => $t->a->owner->id, 'reservation_id' => $reservation->id,
    ], JSON_THROW_ON_ERROR), 'general-receipt-worker.php');

    expect(collect($results)->sortBy(fn (array $result) => isset($result['refused']))->values()->all())
        ->toEqual([['messages' => 1], ['refused' => 'Nothing is waiting for a ZRA receipt.']]);
    inTenant($t->a->property, function () use ($dinner) {
        $message = OutboxMessage::query()->where('event', 'generalReceipt')->sole();
        expect(Posting::query()->findOrFail($dinner->id)->general_receipt_id)->toBe($message->id);
    });
});
