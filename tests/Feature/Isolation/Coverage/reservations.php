<?php

use App\Domains\Inventory\Actions\CreateRoomType;
use Tests\Support\InventoryForm;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

return [
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
