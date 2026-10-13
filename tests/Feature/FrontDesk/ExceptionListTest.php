<?php

use App\Domains\Billing\Actions\CloseCashierShift;
use App\Domains\Billing\Actions\OpenCashierShift;
use App\Domains\FrontDesk\Actions\CloseBusinessDate;
use App\Domains\FrontDesk\Models\ExceptionListItem;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Actions\RejectNoShow;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\NoShowProposal;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;

test('closing carries open no-shows, departures not checked out and open shifts to the next date without stalling', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['business_date' => '2026-12-10']);
    inTenant($property, function () use ($owner, $property) {
        $details = BookingForm::details($owner);
        $unarrived = app(CreateReservation::class)->handle($owner, $details);
        foreach (['2', '3'] as $number) {
            app(CreateRoom::class)->handle($owner, [...InventoryForm::room($details['room_type_id']), 'number' => $number]);
        }
        $overdue = app(CreateReservation::class)->handle($owner, $details);
        $overdue->update(['status' => ReservationStatus::CheckedIn, 'departure' => '2026-12-10']);
        $inHouse = app(CreateReservation::class)->handle($owner, $details);
        $inHouse->update(['status' => ReservationStatus::CheckedIn]);
        $shift = app(OpenCashierShift::class)->handle($owner, ['USD' => 10000]);

        $close = app(CloseBusinessDate::class)->handle(null, $property, '2026-12-10');

        expect($close->completed_at)->not->toBeNull()
            ->and(ExceptionListItem::query()->get()->map->only(['business_date_close_id', 'kind', 'subject_id'])->all())->toEqualCanonicalizing([
                ['business_date_close_id' => $close->id, 'kind' => 'no_show', 'subject_id' => NoShowProposal::query()->where('reservation_id', $unarrived->id)->sole()->id],
                ['business_date_close_id' => $close->id, 'kind' => 'departure', 'subject_id' => $overdue->id],
                ['business_date_close_id' => $close->id, 'kind' => 'cashier_shift', 'subject_id' => $shift->id],
            ])
            ->and(ExceptionListItem::query()->pluck('business_date')->map->toDateString()->unique()->all())->toBe(['2026-12-11']);
    });
});

test('an item resolved before the next close is not carried again', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['business_date' => '2026-12-10']);
    inTenant($property, function () use ($owner, $property) {
        $details = BookingForm::details($owner);
        app(CreateRoom::class)->handle($owner, [...InventoryForm::room($details['room_type_id']), 'number' => '2']);
        $resolved = app(CreateReservation::class)->handle($owner, $details);
        $open = app(CreateReservation::class)->handle($owner, $details);
        $shift = app(OpenCashierShift::class)->handle($owner, ['USD' => 10000]);
        app(CloseBusinessDate::class)->handle(null, $property, '2026-12-10');
        app(RejectNoShow::class)->handle($owner, NoShowProposal::query()->where('reservation_id', $resolved->id)->sole());
        app(CloseCashierShift::class)->handle($owner, $shift, ['USD' => 10000], null);

        $next = app(CloseBusinessDate::class)->handle(null, $property->fresh(), '2026-12-11');

        expect(ExceptionListItem::query()->where('business_date_close_id', $next->id)->get()->map->only(['kind', 'reference'])->all())
            ->toBe([['kind' => 'no_show', 'reference' => $open->reservation_number]]);
    });
});
