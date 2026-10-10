<?php

use App\Domains\Inventory\Actions\CreateRoomType;
use Tests\Support\InventoryForm;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

return [
    'reservations.create' => RouteCoverage::attempt(function (TwoTenants $t) {
        $details = inTenant($t->b->property, fn () => \Tests\Support\BookingForm::details($t->b->owner));
        return $this->actingAs($t->a->owner)->getJson(route('reservations.create', $details))->assertUnprocessable()->assertJsonValidationErrors(['room_type_id', 'rate_plan_id', 'booker_id']);
    }),
    'reservations.store' => RouteCoverage::attempt(function (TwoTenants $t) {
        $details = inTenant($t->b->property, fn () => \Tests\Support\BookingForm::details($t->b->owner));
        $response = $this->actingAs($t->a->owner)->postJson(route('reservations.store'), [...$details, '_property' => $t->a->property->id])->assertUnprocessable()->assertJsonValidationErrors(['room_type_id', 'rate_plan_id', 'booker_id']);
        expect(inTenant($t->b->property, fn () => \App\Domains\Reservations\Models\Reservation::query()->count()))->toBe(0);
        expect(inTenant($t->b->property, fn () => \App\Domains\Inventory\Models\InventoryRecord::query()->sum('sold_count')))->toBe(0);
        return $response;
    }),
    'reservations.show' => RouteCoverage::attempt(function (TwoTenants $t) {
        $reservation = inTenant($t->b->property, fn () => app(\App\Domains\Reservations\Actions\CreateReservation::class)->handle($t->b->owner, \Tests\Support\BookingForm::details($t->b->owner)));
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->get(route('reservations.show', $reservation->reservation_number))->assertNotFound();
        }
        return $responses;
    }),
    'reservations.availability' => RouteCoverage::attempt(function (TwoTenants $t) {
        $type = inTenant($t->b->property, fn () => app(CreateRoomType::class)->handle($t->b->owner, InventoryForm::roomType(['name' => 'Bravo Secret Suite', 'code' => 'BRVX'])));
        $stay = ['arrival' => '2026-12-10', 'departure' => '2026-12-12', 'adults' => 2, 'children' => 0];
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->get(route('reservations.availability', $stay))->assertDontSee('Bravo Secret Suite')->assertDontSee($type->id);
        }
        $responses[] = $this->actingAs($t->a->owner)->getJson(route('reservations.availability', [...$stay, 'room_type_id' => $type->id]))->assertUnprocessable()->assertJsonValidationErrors('room_type_id');

        return $responses;
    }),
];
