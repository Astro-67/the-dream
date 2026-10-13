<?php

use App\Domains\FrontDesk\Actions\CloseBusinessDate;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\NoShowProposal;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;

test('closing proposes each unarrived reservation past its arrival date once without changing its status', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['business_date' => '2026-12-10']);
    inTenant($property, function () use ($owner, $property) {
        $details = BookingForm::details($owner);
        foreach (['2', '3'] as $number) {
            app(CreateRoom::class)->handle($owner, [...InventoryForm::room($details['room_type_id']), 'number' => $number]);
        }
        $unarrived = app(CreateReservation::class)->handle($owner, $details);
        $arrived = app(CreateReservation::class)->handle($owner, $details);
        $arrived->update(['status' => ReservationStatus::CheckedIn]);
        $tomorrow = app(CreateReservation::class)->handle($owner, [...$details, 'arrival' => '2026-12-11']);

        app(CloseBusinessDate::class)->handle(null, $property, '2026-12-10');
        app(CloseBusinessDate::class)->handle(null, $property->fresh(), '2026-12-11');

        $proposal = NoShowProposal::query()->where('reservation_id', $unarrived->id)->sole();
        expect($proposal->status)->toBe('proposed')
            ->and($proposal->business_date->toDateString())->toBe('2026-12-10')
            ->and(NoShowProposal::query()->pluck('reservation_id')->all())->toEqualCanonicalizing([$unarrived->id, $tomorrow->id])
            ->and($unarrived->fresh()->status)->toBe(ReservationStatus::Confirmed)
            ->and($tomorrow->fresh()->status)->toBe(ReservationStatus::Confirmed);
    });
});
