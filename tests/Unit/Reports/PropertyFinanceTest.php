<?php

use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\Folio;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Reports\PropertyFinance;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * One folio per reservation on a fresh room type with $count rooms, in the property in context.
 *
 * @return list<Folio>
 */
function financeReportFolios(User $owner, int $count): array
{
    $details = BookingForm::details($owner);
    for ($number = 2; $number <= $count; $number++) {
        app(CreateRoom::class)->handle($owner, InventoryForm::room($details['room_type_id'], ['number' => (string) $number]));
    }

    return array_map(fn () => Folio::query()->where('reservation_id', app(CreateReservation::class)->handle($owner, $details)->id)->sole(), range(1, $count));
}

test('folio balances list every unsettled folio with its reservation and leave settled folios out', function () {
    [$owner, , $property] = organizationWithOwner();

    inTenant($property, function () use ($owner, $property) {
        [$owing, $settled] = financeReportFolios($owner, 2);
        app(PostExtraCharge::class)->handle($owner, $owing, ['category' => 'minibar', 'quantity' => 1, 'unit_price' => 1500]);
        app(RecordPayment::class)->handle($owner, $owing, ['method' => 'bank_transfer', 'amount' => 500, 'currency' => 'USD']);
        app(PostExtraCharge::class)->handle($owner, $settled, ['category' => 'minibar', 'quantity' => 1, 'unit_price' => 700]);
        app(RecordPayment::class)->handle($owner, $settled, ['method' => 'card', 'amount' => 700, 'currency' => 'USD']);

        expect(app(PropertyFinance::class)->forProperty($property, '2026-12-10', '2026-12-11')['folios'])->toEqual([[
            'id' => $owing->id,
            'reservationNumber' => Reservation::query()->findOrFail($owing->reservation_id)->reservation_number,
            'status' => 'confirmed',
            'departure' => '2026-12-12',
            'departed' => false,
            'currency' => 'USD',
            'balance' => 1000,
        ]]);
    });
});
