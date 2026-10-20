<?php

use App\Domains\Billing\Actions\RecordAdjustment;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Actions\ReversePosting;
use App\Domains\Billing\Models\Folio;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\ReservationLifecycleEvent;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;

test('automatic cancellation observes each property deadline and releases inventory exactly once', function () {
    $this->travelTo(new DateTimeImmutable('2026-12-01 10:00:00 UTC'));
    $t = twoTenants();
    $t->a->property->update(['front_desk_settings' => ['auto_cancel_unpaid_hours' => 2]]);
    $make = fn ($tenant) => inTenant($tenant->property, fn () => app(CreateReservation::class)->handle($tenant->owner, BookingForm::details($tenant->owner)));
    $a = $make($t->a);
    $b = $make($t->b);
    $this->travel(119)->minutes();
    $this->artisan('reservations:cancel-unpaid')->assertSuccessful();
    expect(inTenant($t->a->property, fn () => $a->fresh()->status))->toBe(ReservationStatus::Confirmed);
    $this->travel(1)->minutes();
    $this->artisan('reservations:cancel-unpaid')->assertSuccessful();
    $this->artisan('reservations:cancel-unpaid')->assertSuccessful();
    inTenant($t->a->property, function () use ($a) {
        expect($a->fresh()->status)->toBe(ReservationStatus::Cancelled);
        expect(InventoryRecord::query()->sum('sold_count'))->toBe(0);
        $event = ReservationLifecycleEvent::query()->where('type', 'cancelled')->sole();
        expect($event->actor_id)->toBeNull()->and($event->snapshot['reason'])->toContain('unpaid after 2 hours');
    });
    expect(inTenant($t->b->property, fn () => $b->fresh()->status))->toBe(ReservationStatus::Confirmed);
});

test('automatic cancellation skips deposits channel payments and in-house stays but cancels reversed deposits', function (string $kind, bool $cancelled) {
    $this->travelTo(new DateTimeImmutable('2026-12-01 10:00:00 UTC'));
    [$owner, , $property] = organizationWithOwner();
    $reservation = inTenant($property, function () use ($owner, $kind) {
        $reservation = app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
        if ($kind === 'checked-in') {
            $reservation->update(['status' => ReservationStatus::CheckedIn]);
        } elseif ($kind === 'channel') {
            $reservation->update(['payment_collector' => 'channel', 'channel_collected_amount' => 100]);
        } else {
            $posting = app(RecordPayment::class)->handle($owner, Folio::query()->sole(), ['method' => 'bank_transfer', 'amount' => 100, 'currency' => 'USD']);
            if ($kind === 'refunded') {
                app(RecordAdjustment::class)->handle($owner, Folio::query()->sole(), 'refund', ['amount' => 100, 'reason' => 'Returned deposit', 'method' => 'bank_transfer', 'idempotency_key' => 'refund-deposit']);
            }
            if ($kind === 'reversed') {
                app(ReversePosting::class)->handle($owner, $posting, 'Payment error');
            }
        }

        return $reservation;
    });
    $this->travel(24)->hours();
    $this->artisan('reservations:cancel-unpaid')->assertSuccessful();
    inTenant($property, function () use ($reservation, $cancelled) {
        expect($reservation->fresh()->status === ReservationStatus::Cancelled)->toBe($cancelled);
        expect(Reservation::query()->count())->toBe(1);
    });
})->with([['deposit', false], ['refunded', true], ['reversed', true], ['channel', false], ['checked-in', false]]);

test('automatic cancellation is off at zero hours and never touches channel or imported reservations', function () {
    $this->travelTo(new DateTimeImmutable('2026-12-01 10:00:00 UTC'));
    [$owner, , $property] = organizationWithOwner();
    [$staff, $channel, $imported] = inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        foreach (['102', '103'] as $number) {
            app(CreateRoom::class)->handle($owner, [...InventoryForm::room($details['room_type_id']), 'number' => $number]);
        }
        $make = fn () => app(CreateReservation::class)->handle($owner, $details);

        return [$make(), tap($make(), fn ($r) => $r->forceFill(['channel_reference' => 'BDC-1'])->save()), tap($make(), fn ($r) => $r->forceFill(['imported' => true])->save())];
    });
    $property->update(['front_desk_settings' => ['auto_cancel_unpaid_hours' => 0]]);
    $this->travel(48)->hours();
    $this->artisan('reservations:cancel-unpaid')->assertSuccessful();
    expect(inTenant($property, fn () => $staff->fresh()->status))->toBe(ReservationStatus::Confirmed);
    $property->update(['front_desk_settings' => ['auto_cancel_unpaid_hours' => 2]]);
    $this->artisan('reservations:cancel-unpaid')->assertSuccessful();
    inTenant($property, function () use ($staff, $channel, $imported) {
        expect($staff->fresh()->status)->toBe(ReservationStatus::Cancelled)
            ->and($channel->fresh()->status)->toBe(ReservationStatus::Confirmed)
            ->and($imported->fresh()->status)->toBe(ReservationStatus::Confirmed);
    });
});
