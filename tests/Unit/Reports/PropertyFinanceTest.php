<?php

use App\Domains\Billing\Actions\OpenCashierShift;
use App\Domains\Billing\Actions\OpenFolio;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\RecordAdjustment;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Actions\ReversePosting;
use App\Domains\Billing\Models\Folio;
use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\FrontDesk\Actions\CheckOut;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Models\Room;
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

        expect(app(PropertyFinance::class)->forProperty($property, '2026-12-10', '2026-12-11')['folios']->items())->toEqual([[
            'id' => $owing->id,
            'propertyId' => $property->id,
            'reservationNumber' => Reservation::query()->findOrFail($owing->reservation_id)->reservation_number,
            'status' => 'confirmed',
            'departure' => '2026-12-12',
            'departed' => false,
            'currency' => 'USD',
            'balance' => 1000,
            'guest' => 'Travel Booker',
            'room' => null,
            'charges' => 1500,
            'netPayments' => 500,
            'notYetCharged' => true,
        ]]);
    });
});

test('open balances after departure total each currency separately and leave in-house folios out', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['business_date' => '2026-12-10']);

    inTenant($property, function () use ($owner, $property) {
        [$departing, $inHouse] = financeReportFolios($owner, 2);
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        foreach ([$departing, $inHouse] as $index => $folio) {
            $reservation = Reservation::query()->findOrFail($folio->reservation_id);
            $reservation->update(['room_id' => Room::query()->orderBy('number')->skip($index)->value('id')]);
            $guest = app(CreateGuest::class)->handle($owner, ['name' => 'Guest '.$index, 'documents' => [['type' => 'passport', 'number' => 'P765432'.$index, 'nationality' => 'DE']]]);
            ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);
            app(CheckIn::class)->handle($owner, $reservation);
            app(PostExtraCharge::class)->handle($owner, $folio, ['category' => 'minibar', 'quantity' => 1, 'unit_price' => 1500]);
        }
        $shillings = app(OpenFolio::class)->handle($owner, Reservation::query()->findOrFail($departing->reservation_id), 'TZS');
        app(PostExtraCharge::class)->handle($owner, $shillings, ['category' => 'laundry', 'quantity' => 1, 'unit_price' => 20000]);
        $property->update(['business_date' => '2026-12-12']);
        app(CheckOut::class)->handle($owner, Reservation::query()->findOrFail($departing->reservation_id), 'Company pays by invoice');

        $finance = app(PropertyFinance::class)->forProperty($property, '2026-12-10', '2026-12-11');

        expect(collect($finance['folios']->items())->where('departed', true)->pluck('balance', 'currency')->all())->toEqual(['TZS' => 20000, 'USD' => 1500])
            ->and($finance['openAfterDeparture'])->toEqual([
                ['currency' => 'TZS', 'folios' => 1, 'balance' => 20000],
                ['currency' => 'USD', 'folios' => 1, 'balance' => 1500],
            ]);
    });
});

test('payments in the date range group by method currency and cashier shift with refunds and reversals netted', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['business_date' => '2026-12-09']);

    inTenant($property, function () use ($owner, $property) {
        [$dollars] = financeReportFolios($owner, 1);
        $shillings = app(OpenFolio::class)->handle($owner, Reservation::query()->findOrFail($dollars->reservation_id), 'TZS');
        $owner->forceFill(['name' => 'Asha Cashier'])->save();
        $shift = app(OpenCashierShift::class)->handle($owner, ['TZS' => 0, 'USD' => 0]);
        $pay = fn (Folio $folio, string $method, int $amount) => app(RecordPayment::class)->handle($owner, $folio, ['method' => $method, 'amount' => $amount, 'currency' => $folio->currency->value]);
        $pay($dollars, 'card', 9900);
        $property->update(['business_date' => '2026-12-10']);
        $pay($dollars, 'cash', 4000);
        $pay($shillings, 'cash', 50000);
        $pay($dollars, 'card', 3000);
        $transfer = $pay($dollars, 'bank_transfer', 2000);
        app(RecordAdjustment::class)->handle($owner, $dollars, 'refund', ['amount' => 500, 'reason' => 'Overpaid', 'idempotency_key' => 'refund-1', 'method' => 'card']);
        $charge = app(PostExtraCharge::class)->handle($owner, $dollars, ['category' => 'minibar', 'quantity' => 1, 'unit_price' => 800]);
        app(ReversePosting::class)->handle($owner, $charge, 'Not consumed');
        $property->update(['business_date' => '2026-12-11']);
        app(ReversePosting::class)->handle($owner, $transfer, 'Bounced');

        $payments = app(PropertyFinance::class)->forProperty($property, '2026-12-10', '2026-12-11')['payments'];

        expect($payments['rows'])->toEqual([
            ['method' => 'bank_transfer', 'currency' => 'USD', 'shiftId' => null, 'cashier' => null, 'shiftOpenedAt' => null, 'payments' => 2000, 'refunds' => 0, 'reversals' => -2000, 'net' => 0],
            ['method' => 'card', 'currency' => 'USD', 'shiftId' => null, 'cashier' => null, 'shiftOpenedAt' => null, 'payments' => 3000, 'refunds' => 500, 'reversals' => 0, 'net' => 2500],
            ['method' => 'cash', 'currency' => 'TZS', 'shiftId' => $shift->id, 'cashier' => 'Asha Cashier', 'shiftOpenedAt' => $shift->opened_at->toIso8601String(), 'payments' => 50000, 'refunds' => 0, 'reversals' => 0, 'net' => 50000],
            ['method' => 'cash', 'currency' => 'USD', 'shiftId' => $shift->id, 'cashier' => 'Asha Cashier', 'shiftOpenedAt' => $shift->opened_at->toIso8601String(), 'payments' => 4000, 'refunds' => 0, 'reversals' => 0, 'net' => 4000],
        ])->and($payments['totals'])->toEqual([
            ['currency' => 'TZS', 'payments' => 50000, 'refunds' => 0, 'reversals' => 0, 'net' => 50000],
            ['currency' => 'USD', 'payments' => 9000, 'refunds' => 500, 'reversals' => -2000, 'net' => 6500],
        ]);
    });
});

test('a refund reversal is received on its own business date without repeating the earlier refund', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['business_date' => '2026-12-10']);

    inTenant($property, function () use ($owner, $property) {
        [$folio] = financeReportFolios($owner, 1);
        $refund = app(RecordAdjustment::class)->handle($owner, $folio, 'refund', [
            'amount' => 500, 'reason' => 'Overpaid', 'idempotency_key' => 'refund-reversal', 'method' => 'card',
        ]);
        $property->update(['business_date' => '2026-12-11']);
        app(ReversePosting::class)->handle($owner, $refund, 'Refund was not sent');

        $finance = app(PropertyFinance::class)->forProperty($property, '2026-12-11', '2026-12-11');

        expect($finance['payments']['rows'])->toEqual([[
            'method' => 'card', 'currency' => 'USD', 'shiftId' => null, 'cashier' => null, 'shiftOpenedAt' => null,
            'payments' => 0, 'refunds' => 0, 'reversals' => 500, 'net' => 500,
        ]])->and($finance['payments']['totals'])->toEqual([[
            'currency' => 'USD', 'payments' => 0, 'refunds' => 0, 'reversals' => 500, 'net' => 500,
        ]])->and($finance['folios']->total())->toBe(0);
    });
});

// protects=reconciliation rows match the posted ledger; fails_when=a reversed charge or refunded payment counts twice, or settled folios are omitted when asked.
test('folio balance rows carry guest, room, posted charges and net payments, and show settled only on request', function () {
    [$owner, , $property] = organizationWithOwner();

    inTenant($property, function () use ($owner, $property) {
        [$owing, $settled, $credit] = financeReportFolios($owner, 3);
        $reservation = Reservation::query()->findOrFail($owing->reservation_id);
        $reservation->update(['room_id' => Room::query()->orderBy('number')->value('id')]);
        ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => app(CreateGuest::class)->handle($owner, ['name' => 'Amina Report'])->id]);
        $charge = app(PostExtraCharge::class)->handle($owner, $owing, ['category' => 'minibar', 'quantity' => 1, 'unit_price' => 1500]);
        app(PostExtraCharge::class)->handle($owner, $owing, ['category' => 'laundry', 'quantity' => 1, 'unit_price' => 900]);
        app(ReversePosting::class)->handle($owner, $charge, 'Not taken');
        app(RecordPayment::class)->handle($owner, $owing, ['method' => 'bank_transfer', 'amount' => 500, 'currency' => 'USD']);
        app(RecordAdjustment::class)->handle($owner, $owing, 'refund', ['amount' => 100, 'reason' => 'Overpaid', 'idempotency_key' => 'r', 'method' => 'bank_transfer']);
        app(PostExtraCharge::class)->handle($owner, $settled, ['category' => 'minibar', 'quantity' => 1, 'unit_price' => 700]);
        app(RecordPayment::class)->handle($owner, $settled, ['method' => 'card', 'amount' => 700, 'currency' => 'USD']);
        app(RecordPayment::class)->handle($owner, $credit, ['method' => 'card', 'amount' => 300, 'currency' => 'USD']);
        $finance = app(PropertyFinance::class);

        $rows = collect($finance->forProperty($property, '2026-12-10', '2026-12-12')['folios']->items())->keyBy('id');
        expect($rows->keys()->sort()->values()->all())->toEqual(collect([$owing->id, $credit->id])->sort()->values()->all())
            ->and($rows[$owing->id])->toMatchArray(['guest' => 'Amina Report', 'room' => Room::query()->orderBy('number')->value('number'), 'charges' => 900, 'netPayments' => 400, 'balance' => 500, 'notYetCharged' => true])
            ->and($rows[$credit->id])->toMatchArray(['guest' => 'Travel Booker', 'room' => null, 'charges' => 0, 'netPayments' => 300, 'balance' => -300]);

        $withSettled = collect($finance->forProperty($property, '2026-12-12', '2026-12-12', settled: true)['folios']->items())->keyBy('id');
        expect($withSettled)->toHaveCount(3)->and($withSettled[$settled->id])->toMatchArray(['charges' => 700, 'netPayments' => 700, 'balance' => 0])
            ->and(collect($finance->forProperty($property, '2026-12-20', '2026-12-21', settled: true)['folios']->items())->pluck('id')->doesntContain($settled->id))->toBeTrue();
    });
});

// protects=Show settled follows departure dates exactly and counts its own rows; fails_when=a boundary day is dropped or the unsettled count truncates the page.
test('show settled includes both departure boundaries, keeps currencies apart and paginates its own total', function () {
    [$owner, , $property] = organizationWithOwner();

    inTenant($property, function () use ($owner, $property) {
        $folios = financeReportFolios($owner, 52);
        foreach ($folios as $index => $folio) {
            // Departures 12, 13 and 14 December, in turn.
            Reservation::query()->whereKey($folio->reservation_id)->update(['departure' => '2026-12-1'.(2 + $index % 3)]);
        }
        $shillings = app(OpenFolio::class)->handle($owner, Reservation::query()->findOrFail($folios[0]->reservation_id), 'TZS');
        app(PostExtraCharge::class)->handle($owner, $shillings, ['category' => 'laundry', 'quantity' => 1, 'unit_price' => 20000]);
        $finance = app(PropertyFinance::class);

        $both = $finance->forProperty($property, '2026-12-12', '2026-12-13', settled: true)['folios'];
        $tzs = collect($both->items())->firstWhere('currency', 'TZS');
        $all = $finance->forProperty($property, '2026-12-12', '2026-12-14', settled: true)['folios'];
        expect($both->total())->toBe(36)->and($all->total())->toBe(53)->and($all->items())->toHaveCount(50)->and($all->lastPage())->toBe(2)
            ->and(collect($finance->folios(collect([$property]), settled: true, from: '2026-12-12', to: '2026-12-13')->items())->pluck('departure')->unique()->sort()->values()->all())->toBe(['2026-12-12', '2026-12-13'])
            ->and($tzs)->toMatchArray(['currency' => 'TZS', 'charges' => 20000, 'balance' => 20000])
            ->and($finance->forProperty($property, '2026-12-14', '2026-12-14', settled: true)['folios']->total())->toBe(18)
            ->and($finance->forProperty($property, '2026-12-14', '2026-12-14')['folios']->total())->toBe(1);
    });
});
