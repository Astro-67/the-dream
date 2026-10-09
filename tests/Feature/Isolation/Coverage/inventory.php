<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Actions\TakeRoomOutOfService;
use App\Domains\Inventory\Models\OutOfServicePeriod;
use App\Domains\Inventory\Models\Room;
use App\Domains\Inventory\Models\RoomType;
use Tests\Support\InventoryForm;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

/** Bravo's room type, room and out-of-service period, named so a leak is easy to spot. @return array{0: RoomType, 1: Room, 2: OutOfServicePeriod} */
$bravoInventory = fn (TwoTenants $t): array => inTenant($t->b->property, function () use ($t) {
    $roomType = app(CreateRoomType::class)->handle($t->b->owner, InventoryForm::roomType(['name' => 'Bravo Secret Suite', 'code' => 'BRVX']));

    $room = app(CreateRoom::class)->handle($t->b->owner, InventoryForm::room($roomType->id, ['number' => 'B-901']));
    $period = app(TakeRoomOutOfService::class)->handle($t->b->owner, $room, ['starts_on' => now()->addDays(30)->toDateString(), 'returns_on' => now()->addDays(31)->toDateString(), 'reason' => 'Bravo Secret Leak']);

    return [$roomType, $room, $period];
});

/** @return array<string, mixed> */
$bravoSnapshot = fn (TwoTenants $t): array => inTenant($t->b->organization, fn () => [
    'roomTypes' => RoomType::query()->orderBy('id')->get()->map->only(['id', 'property_id', ...RoomType::FIELDS])->all(),
    'periods' => OutOfServicePeriod::query()->orderBy('id')->get()->map->details()->all(),
    'rooms' => Room::query()->orderBy('id')->get()->map(fn (Room $r) => [...$r->only(['id', 'property_id', 'number', 'floor', 'room_type_id']), 'deactivated_at' => $r->deactivated_at?->toIso8601String()])->all(),
]);

// A read must not show Bravo's inventory to any Alpha member.
$readAsEveryone = function (TwoTenants $t, string $route) use ($bravoInventory) {
    $bravoInventory($t);
    $responses = [];

    foreach ($t->a->members() as $member) {
        $response = $this->actingAs($member)->get(route($route));
        $response->assertDontSee('Bravo Secret Suite')->assertDontSee('BRVX')->assertDontSee('B-901')->assertDontSee('Bravo Secret Leak');
        $responses[] = $response;
    }

    return $responses;
};

/*
 * Each write is tried from Alpha's own property (Bravo's record is not found there)
 * and with Bravo's property as intent (refused). Bravo's inventory must be unchanged.
 */
/** @var array<string, string> */
$oosForm = ['starts_on' => now()->addDays(40)->toDateString(), 'returns_on' => now()->addDays(41)->toDateString(), 'reason' => 'Hijacked'];

$writeAttempt = fn (Closure $send) => function (TwoTenants $t) use ($send, $bravoInventory, $bravoSnapshot) {
    [$roomType, $room, $period] = $bravoInventory($t);
    $before = $bravoSnapshot($t);
    $responses = $send->call($this, $t, $roomType, $room, $period);
    expect($bravoSnapshot($t))->toBe($before);

    return $responses;
};

return [
    'room-types.index' => RouteCoverage::attempt(fn (TwoTenants $t) => $readAsEveryone->call($this, $t, 'room-types.index')),
    'room-types.create' => RouteCoverage::visit(),
    'room-types.store' => RouteCoverage::attempt($writeAttempt(fn (TwoTenants $t) => [
        $this->actingAs($t->a->owner)->post(route('room-types.store'), InventoryForm::roomType(['_property' => $t->b->property->id]))->assertForbidden(),
        // Bravo's code is free in Alpha's property: codes are unique per property.
        $this->actingAs($t->a->owner)->post(route('room-types.store'), InventoryForm::roomType(['_property' => $t->a->property->id, 'code' => 'BRVX', 'name' => 'Alpha Suite']))->assertSessionHasNoErrors(),
    ])),
    'room-types.edit' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoInventory) {
        [$roomType] = $bravoInventory($t);

        return $this->actingAs($t->a->owner)->get(route('room-types.edit', $roomType->id))->assertNotFound();
    }),
    'room-types.update' => RouteCoverage::attempt($writeAttempt(fn (TwoTenants $t, RoomType $roomType) => [
        $this->actingAs($t->a->owner)->put(route('room-types.update', $roomType->id), InventoryForm::roomType(['_property' => $t->a->property->id, 'name' => 'Hijacked']))->assertNotFound(),
        $this->actingAs($t->a->owner)->put(route('room-types.update', $roomType->id), InventoryForm::roomType(['_property' => $t->b->property->id, 'name' => 'Hijacked']))->assertForbidden(),
    ])),
    'rooms.index' => RouteCoverage::attempt(fn (TwoTenants $t) => $readAsEveryone->call($this, $t, 'rooms.index')),
    'rooms.create' => RouteCoverage::attempt(fn (TwoTenants $t) => $readAsEveryone->call($this, $t, 'rooms.create')),
    'rooms.store' => RouteCoverage::attempt($writeAttempt(fn (TwoTenants $t, RoomType $roomType) => [
        $this->actingAs($t->a->owner)->post(route('rooms.store'), InventoryForm::room($roomType->id, ['_property' => $t->a->property->id, 'number' => '777']))->assertSessionHasErrors('room_type_id'),
        $this->actingAs($t->a->owner)->post(route('rooms.store'), InventoryForm::room($roomType->id, ['_property' => $t->b->property->id, 'number' => '777']))->assertForbidden(),
    ])),
    'rooms.edit' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoInventory) {
        [, $room] = $bravoInventory($t);

        return $this->actingAs($t->a->owner)->get(route('rooms.edit', $room->id))->assertNotFound();
    }),
    'rooms.update' => RouteCoverage::attempt($writeAttempt(fn (TwoTenants $t, RoomType $roomType, Room $room) => [
        $this->actingAs($t->a->owner)->put(route('rooms.update', $room->id), ['_property' => $t->a->property->id, 'number' => '999'])->assertNotFound(),
        $this->actingAs($t->a->owner)->put(route('rooms.update', $room->id), ['_property' => $t->b->property->id, 'number' => '999'])->assertForbidden(),
    ])),
    'rooms.move' => RouteCoverage::attempt($writeAttempt(function (TwoTenants $t, RoomType $roomType, Room $room) {
        $alphaType = inTenant($t->a->property, fn () => app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType()));
        $alphaRoom = inTenant($t->a->property, fn () => app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($alphaType->id)));

        return [
            $this->actingAs($t->a->owner)->put(route('rooms.move', $room->id), ['_property' => $t->a->property->id, 'room_type_id' => $alphaType->id])->assertNotFound(),
            $this->actingAs($t->a->owner)->put(route('rooms.move', $room->id), ['_property' => $t->b->property->id, 'room_type_id' => $roomType->id])->assertForbidden(),
            // Alpha's own room can't be moved into Bravo's room type.
            $this->actingAs($t->a->owner)->put(route('rooms.move', $alphaRoom->id), ['_property' => $t->a->property->id, 'room_type_id' => $roomType->id])->assertSessionHasErrors('room_type_id'),
        ];
    })),
    'out-of-service.store' => RouteCoverage::attempt($writeAttempt(fn (TwoTenants $t, RoomType $roomType, Room $room) => [
        $this->actingAs($t->a->owner)->post(route('out-of-service.store', $room->id), [...$oosForm, '_property' => $t->a->property->id])->assertNotFound(),
        $this->actingAs($t->a->owner)->post(route('out-of-service.store', $room->id), [...$oosForm, '_property' => $t->b->property->id])->assertForbidden(),
    ])),
    'out-of-service.update' => RouteCoverage::attempt($writeAttempt(fn (TwoTenants $t, RoomType $roomType, Room $room, OutOfServicePeriod $period) => [
        $this->actingAs($t->a->owner)->put(route('out-of-service.update', $period->id), [...$oosForm, '_property' => $t->a->property->id])->assertNotFound(),
        $this->actingAs($t->a->owner)->put(route('out-of-service.update', $period->id), [...$oosForm, '_property' => $t->b->property->id])->assertForbidden(),
    ])),
    'rooms.deactivate' => RouteCoverage::attempt($writeAttempt(fn (TwoTenants $t, RoomType $roomType, Room $room) => [
        $this->actingAs($t->a->owner)->post(route('rooms.deactivate', $room->id), ['_property' => $t->a->property->id])->assertNotFound(),
        $this->actingAs($t->a->owner)->post(route('rooms.deactivate', $room->id), ['_property' => $t->b->property->id])->assertForbidden(),
    ])),
];
