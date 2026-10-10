<?php

use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;

test('front desk amends a confirmed stay with atomic inventory and visible history', function () {
    $t = twoTenants();
    [$reservation, $details] = inTenant($t->a->property, function () use ($t) {
        $details = BookingForm::details($t->a->owner);

        return [app(CreateReservation::class)->handle($t->a->owner, $details), $details];
    });
    $actor = $t->a->member(OrganizationRole::FrontDesk);
    $this->actingAs($actor);
    $this->patch(route('reservations.amend', $reservation), [...$details, 'arrival' => '2026-12-11', 'children' => 0, 'room_id' => null, 'pricing' => 'keep', '_property' => $t->a->property->id])->assertRedirect(route('reservations.show', $reservation));
    $this->get(route('reservations.show', $reservation))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('reservation.arrival', '2026-12-11T00:00:00.000000Z')
        ->has('reservation.price_lines', 1)
        ->where('reservation.price_lines.0.amount', 10000)
        ->has('amendments', 1)
        ->where('amendments.0.actor.name', $actor->name)
        ->where('amendments.0.snapshot.before.children', 1)
        ->where('amendments.0.snapshot.after.children', 0));
    inTenant($t->a->property, function () use ($reservation) {
        expect(InventoryRecord::query()->whereBetween('date', ['2026-12-10', '2026-12-11'])->orderBy('date')->pluck('sold_count')->all())->toBe([0, 1]);
        expect($reservation->fresh()->status)->toBe(ReservationStatus::Confirmed)
            ->and($reservation->lifecycleEvents()->pluck('type')->all())->toBe(['created', 'modified']);
    });
});
