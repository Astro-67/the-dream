<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Models\Room;
use App\Domains\Inventory\Models\RoomType;
use Illuminate\Support\Facades\DB;
use Tests\Support\InventoryForm;
use Tests\Support\TenantFixture;

/** @return list<int> VFMS room numbers of the property's rooms, by room number */
function vfmsRoomIds(TenantFixture $tenant, bool $otherProperty = false): array
{
    return inTenant($otherProperty ? $tenant->otherProperty : $tenant->property, fn () => Room::query()->orderBy('number')->pluck('vfms_room_id')->all());
}

function addRooms(TenantFixture $tenant, array $numbers, bool $otherProperty = false): void
{
    inTenant($otherProperty ? $tenant->otherProperty : $tenant->property, function () use ($tenant, $numbers) {
        $type = RoomType::query()->first() ?? app(CreateRoomType::class)->handle($tenant->owner, InventoryForm::roomType());
        foreach ($numbers as $number) {
            app(CreateRoom::class)->handle($tenant->owner, InventoryForm::room($type->id, ['number' => $number]));
        }
    });
}

// protects=every room has a VFMS room number, the next whole number in its property (spec #156); fails_when=numbers repeat, skip, or continue across properties; no_claim=what VFMS does with the number.
test('new rooms get the next VFMS room number in their property', function () {
    $t = twoTenants();
    addRooms($t->a, ['101', '102']);
    addRooms($t->a, ['201'], otherProperty: true);
    addRooms($t->b, ['101']);

    expect(vfmsRoomIds($t->a))->toBe([1, 2])
        ->and(vfmsRoomIds($t->a, otherProperty: true))->toBe([1])
        ->and(vfmsRoomIds($t->b))->toBe([1]);
});

// protects=a VFMS room number is never reused or edited (spec #156); fails_when=a room's number can be changed or a deactivated room's number is handed out again; no_claim=room deletion (rooms are never deleted).
test('a VFMS room number is never edited or reused', function () {
    $t = twoTenants();
    addRooms($t->a, ['101', '102']);
    inTenant($t->a->property, function () {
        Room::query()->where('number', '102')->update(['deactivated_at' => now()]);
        expect(fn () => Room::query()->where('number', '101')->sole()->forceFill(['vfms_room_id' => 9])->save())->toThrow(LogicException::class);
    });
    addRooms($t->a, ['103']);

    expect(vfmsRoomIds($t->a))->toBe([1, 2, 3]);
});

// protects=existing rooms are numbered by the migration, uniquely per property (spec #156 AC 10); fails_when=a room is left unnumbered or two rooms of a property share a number; no_claim=the order rooms are numbered in.
test('the migration numbers existing rooms per property', function () {
    $t = twoTenants();
    addRooms($t->a, ['101', '102', '103']);
    addRooms($t->a, ['201'], otherProperty: true);
    addRooms($t->b, ['101', '102']);
    $migration = require database_path('migrations/2026_11_01_000110_inventory_add_rooms_vfms_room_id.php');
    $migration->down();
    $migration->up();

    $rows = DB::table('rooms')->get(['property_id', 'vfms_room_id'])->groupBy('property_id')->map(fn ($rooms) => $rooms->pluck('vfms_room_id')->sort()->values()->all());
    expect($rows->values()->sort()->values()->all())->toBe([[1], [1, 2], [1, 2, 3]]);
    addRooms($t->a, ['104']);
    expect(vfmsRoomIds($t->a))->toBe([1, 2, 3, 4]);
});
