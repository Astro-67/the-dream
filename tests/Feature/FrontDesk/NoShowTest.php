<?php

use App\Domains\Billing\Models\FeeProposal;
use App\Domains\FrontDesk\Actions\CloseBusinessDate;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Reservations\Actions\ConfirmNoShow;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Actions\RejectNoShow;
use App\Domains\Reservations\Enums\NoShowProposalStatus;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\NoShowProposal;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use Illuminate\Validation\ValidationException;
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
        expect($proposal->status)->toBe(NoShowProposalStatus::Proposed)
            ->and($proposal->business_date->toDateString())->toBe('2026-12-10')
            ->and(NoShowProposal::query()->pluck('reservation_id')->all())->toEqualCanonicalizing([$unarrived->id, $tomorrow->id])
            ->and($unarrived->fresh()->status)->toBe(ReservationStatus::Confirmed)
            ->and($tomorrow->fresh()->status)->toBe(ReservationStatus::Confirmed);
    });
});

/** A 2-night stay arriving 2026-12-10 that night audit proposed as a no-show. */
function proposedNoShow(User $owner, Property $property): NoShowProposal
{
    $property->update(['business_date' => '2026-12-10']);
    $details = BookingForm::details($owner);
    RatePlan::query()->whereKey($details['rate_plan_id'])->update(['no_show_charge' => 'first_night']);
    app(CreateReservation::class)->handle($owner, $details);
    app(CloseBusinessDate::class)->handle(null, $property, '2026-12-10');

    return NoShowProposal::query()->sole();
}

test('confirming a no-show releases its inventory, proposes the no-show fee and records the lifecycle event', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner, $property) {
        $proposal = proposedNoShow($owner, $property);
        $reservation = $proposal->reservation;
        $before = InventoryRecord::query()->whereDate('date', '2026-12-11')->sole()->sold_count;

        app(ConfirmNoShow::class)->handle($owner, $proposal);

        expect($reservation->fresh()->status)->toBe(ReservationStatus::NoShow)
            ->and(InventoryRecord::query()->whereDate('date', '2026-12-11')->sole()->sold_count)->toBe($before - 1)
            ->and(FeeProposal::query()->sole()->only(['kind', 'amount', 'status']))->toBe(['kind' => 'no_show', 'amount' => 11000, 'status' => 'proposed'])
            ->and($reservation->lifecycleEvents()->reorder()->latest('sequence')->first()->type)->toBe('no_show_confirmed')
            ->and($proposal->fresh()->only(['status', 'resolved_by']))->toBe(['status' => NoShowProposalStatus::Confirmed, 'resolved_by' => $owner->id]);
        expect(fn () => app(ConfirmNoShow::class)->handle($owner, $proposal))->toThrow(ValidationException::class);
    });
});

test('rejecting a no-show resolves the proposal and leaves the reservation as it was', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner, $property) {
        $proposal = proposedNoShow($owner, $property);

        app(RejectNoShow::class)->handle($owner, $proposal);

        expect($proposal->reservation->fresh()->status)->toBe(ReservationStatus::Confirmed)
            ->and($proposal->reservation->lifecycleEvents()->count())->toBe(1)
            ->and(FeeProposal::query()->count())->toBe(0)
            ->and($proposal->fresh()->status)->toBe(NoShowProposalStatus::Rejected);
        expect(fn () => app(ConfirmNoShow::class)->handle($owner, $proposal))->toThrow(ValidationException::class);
    });
});
