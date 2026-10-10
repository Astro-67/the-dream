<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Reservations\Models\Reservation;
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
