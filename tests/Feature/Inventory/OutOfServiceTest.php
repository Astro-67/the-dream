<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Inventory\Models\Room;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\Property;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\InventoryForm;

beforeEach(function () {
    $this->travelTo(now()->setTimezone('Africa/Dar_es_Salaam')->setDate(2026, 10, 8)->setTime(9, 0));
    config(['inventory.horizon_years' => 1]);
});

/** A room type with two rooms; returns [type, first room]. @return array{0: RoomType, 1: Room} */
function twoRoomsIn(Property $property): array
{
    return inTenant($property, function () {
        $type = app(CreateRoomType::class)->handle(null, InventoryForm::roomType());
        $room = app(CreateRoom::class)->handle(null, InventoryForm::room($type->id));
        app(CreateRoom::class)->handle(null, InventoryForm::room($type->id, ['number' => '102']));

        return [$type, $room];
    });
}

/** @return array<string, int> sellable count by date for the given dates */
function sellableOn(Property $property, RoomType $type, array $dates): array
{
    return inTenant($property, fn () => collect($dates)->mapWithKeys(fn (string $date) => [
        $date => InventoryRecord::query()->where('room_type_id', $type->id)->whereDate('date', $date)->sole()->sellable_count,
    ])->all());
}

function seedSold(Property $property, RoomType $type, string $date, int $sold): void
{
    inTenant($property, function () use ($type, $date, $sold) {
        $record = InventoryRecord::query()->where('room_type_id', $type->id)->whereDate('date', $date)->sole();
        $record->sold_count = $sold;
        $record->save();
    });
}

test('a property manager takes a room out of service and sellable drops on those dates only', function () {
    $t = twoTenants();
    [$type, $room] = twoRoomsIn($t->a->property);

    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))
        ->post(route('out-of-service.store', $room), ['_property' => $t->a->property->id, 'starts_on' => '2026-10-10', 'returns_on' => '2026-10-12', 'reason' => 'Leaking shower'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('rooms.edit', $room));

    expect(sellableOn($t->a->property, $type, ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12']))
        ->toBe(['2026-10-09' => 2, '2026-10-10' => 1, '2026-10-11' => 1, '2026-10-12' => 2]);

    $audit = Activity::query()->where('description', 'Room taken out of service')->sole();
    expect($audit->getExtraProperty('attributes'))->toEqual(['room_id' => $room->id, 'starts_on' => '2026-10-10', 'returns_on' => '2026-10-12', 'reason' => 'Leaking shower']);
});

test('out-of-service periods of one room never overlap', function () {
    $t = twoTenants();
    [, $room] = twoRoomsIn($t->a->property);
    $p = ['_property' => $t->a->property->id, 'reason' => 'Repainting'];
    $this->actingAs($t->a->owner);

    $this->post(route('out-of-service.store', $room), [...$p, 'starts_on' => '2026-10-10', 'returns_on' => '2026-10-15'])->assertSessionHasNoErrors();
    $this->post(route('out-of-service.store', $room), [...$p, 'starts_on' => '2026-10-14', 'returns_on' => '2026-10-20'])
        ->assertSessionHasErrors(['starts_on' => 'Room 101 is already out of service from 2026-10-10 until 2026-10-15.']);
    // Back in service on the 15th, so another period can start that day.
    $this->post(route('out-of-service.store', $room), [...$p, 'starts_on' => '2026-10-15', 'returns_on' => '2026-10-20'])->assertSessionHasNoErrors();

    expect(inTenant($t->a->property, fn () => $room->outOfServicePeriods()->count()))->toBe(2);
});
