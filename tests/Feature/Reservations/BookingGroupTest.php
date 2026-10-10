<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;

test('front desk books several rooms with one booker and separate numbers prices and created events', function () {
    $t = twoTenants();
    $details = inTenant($t->a->property, function () use ($t) {
        $details = BookingForm::details($t->a->owner);
        app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($details['room_type_id'], ['number' => '102']));

        return $details;
    });
    $response = $this->actingAs($t->a->owner)->post(route('reservations.store'), [...$details, 'rooms' => 2, '_property' => $t->a->property->id]);
    $response->assertRedirect();
    inTenant($t->a->property, function () use ($details) {
        $members = Reservation::query()->orderBy('reservation_number')->get();
        expect($members)->toHaveCount(2)
            ->and($members->pluck('reservation_number')->unique())->toHaveCount(2)
            ->and($members->pluck('booking_group_id')->unique()->all())->toHaveCount(1)
            ->and($members->first()->booking_group_id)->not->toBeNull();
        foreach ($members as $member) {
            expect($member->booker_id)->toBe($details['booker_id'])
                ->and($member->priceLines->pluck('amount')->all())->toBe([11000, 11000])
                ->and($member->lifecycleEvents->sole()->type)->toBe('created');
        }
    });
});

test('group details link siblings and cancelling one member leaves the others confirmed', function () {
    $t = twoTenants();
    [$member, $sibling] = inTenant($t->a->property, function () use ($t) {
        $details = BookingForm::details($t->a->owner);
        app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($details['room_type_id'], ['number' => '102']));
        app(CreateReservation::class)->handle($t->a->owner, [...$details, 'rooms' => 2]);

        return Reservation::query()->orderBy('reservation_number')->get()->all();
    });
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)->get(route('reservations.show', $member))
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('groupMembers', 1)->where('groupMembers.0.reservation_number', $sibling->reservation_number));
    $this->post(route('reservations.cancel', $member), ['reason' => 'One guest withdrew', '_property' => $t->a->property->id])->assertRedirect();
    $this->get(route('reservations.show', $sibling))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reservation.status', 'confirmed')->where('groupMembers.0.status', 'cancelled'));
    inTenant($t->a->property, function () use ($member, $sibling) {
        expect($member->lifecycleEvents()->pluck('type')->all())->toBe(['created', 'cancelled'])
            ->and($sibling->lifecycleEvents()->pluck('type')->all())->toBe(['created'])
            ->and(InventoryRecord::query()->whereBetween('date', ['2026-12-10', '2026-12-11'])->orderBy('date')->pluck('sold_count')->all())->toBe([1, 1]);
    });
});

test('booking groups refuse invalid room counts without creating reservations', function (int $rooms) {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner, $rooms) {
        $details = BookingForm::details($owner);
        expect(fn () => app(CreateReservation::class)->handle($owner, [...$details, 'rooms' => $rooms]))->toThrow(ValidationException::class);
        expect(Reservation::query()->count())->toBe(0);
    });
})->with([0, -1, 21]);
