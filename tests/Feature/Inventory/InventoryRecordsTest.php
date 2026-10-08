<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Actions\DeactivateRoom;
use App\Domains\Inventory\Actions\MoveRoom;
use App\Domains\Inventory\Models\InventoryRecord;
use Tests\Support\InventoryForm;

test('moving a room recalculates both types and never changes sold inventory', function () {
    [, , $property] = organizationWithOwner();
    inTenant($property, function () {
        $old = app(CreateRoomType::class)->handle(null, InventoryForm::roomType());
        $new = app(CreateRoomType::class)->handle(null, InventoryForm::roomType(['code' => 'NEW']));
        $room = app(CreateRoom::class)->handle(null, InventoryForm::room($old->id));
        $record = InventoryRecord::query()->where('room_type_id', $old->id)->firstOrFail();
        $record->sold_count = 1;
        $record->save();
        app(MoveRoom::class)->handle(null, $room, $new);
        expect(InventoryRecord::query()->where('room_type_id', $old->id)->pluck('sellable_count')->unique()->all())->toBe([0])
            ->and(InventoryRecord::query()->where('room_type_id', $new->id)->pluck('sellable_count')->unique()->all())->toBe([1])
            ->and($record->fresh()->sold_count)->toBe(1);
    });
});

test('deactivating a room reduces every sellable count once and preserves sold inventory', function () {
    [, , $property] = organizationWithOwner();
    inTenant($property, function () {
        $type = app(CreateRoomType::class)->handle(null, InventoryForm::roomType());
        $room = app(CreateRoom::class)->handle(null, InventoryForm::room($type->id));
        $record = InventoryRecord::query()->firstOrFail();
        $record->sold_count = 1;
        $record->save();
        app(DeactivateRoom::class)->handle(null, $room);
        app(DeactivateRoom::class)->handle(null, $room);

        expect(InventoryRecord::query()->pluck('sellable_count')->unique()->all())->toBe([0])
            ->and($record->fresh()->sold_count)->toBe(1);
    });
});

test('adding a room updates every date without changing sold counts or other properties', function () {
    config(['inventory.horizon_years' => 1]);
    $t = twoTenants();
    inTenant($t->a->otherProperty, fn () => app(CreateRoomType::class)->handle(null, InventoryForm::roomType()));
    inTenant($t->b->property, fn () => app(CreateRoomType::class)->handle(null, InventoryForm::roomType()));

    inTenant($t->a->property, function () {
        $type = app(CreateRoomType::class)->handle(null, InventoryForm::roomType());
        $record = InventoryRecord::query()->where('room_type_id', $type->id)->firstOrFail();
        $record->sold_count = 3;
        $record->save();
        app(CreateRoom::class)->handle(null, InventoryForm::room($type->id));

        expect(InventoryRecord::query()->where('room_type_id', $type->id)->pluck('sellable_count')->unique()->all())->toBe([1])
            ->and($record->fresh()->sold_count)->toBe(3);
    });
    foreach ([$t->a->otherProperty, $t->b->property] as $property) {
        expect(inTenant($property, fn () => InventoryRecord::query()->pluck('sellable_count')->unique()->all()))->toBe([0]);
    }
});

test('creating a room type creates zero sellable and sold inventory through the configured horizon', function () {
    $this->travelTo(now()->setDate(2026, 10, 8)->startOfDay());
    config(['inventory.horizon_years' => 1]);
    [, , $property] = organizationWithOwner();

    inTenant($property, function () {
        $type = app(CreateRoomType::class)->handle(null, InventoryForm::roomType());
        $records = InventoryRecord::query()->where('room_type_id', $type->id)->orderBy('date')->get();

        expect($records)->toHaveCount(366)
            ->and($records->first()->date->toDateString())->toBe('2026-10-08')
            ->and($records->last()->date->toDateString())->toBe('2027-10-08')
            ->and($records->pluck('sellable_count')->unique()->all())->toBe([0])
            ->and($records->pluck('sold_count')->unique()->all())->toBe([0]);
    });
});

test('the daily extension fills the horizon for every property and is safe to run twice', function () {
    $this->travelTo(now()->setDate(2026, 10, 8)->startOfDay());
    config(['inventory.horizon_years' => 1]);
    $t = twoTenants();
    $types = [];
    foreach ([$t->a->property, $t->b->property] as $property) {
        $types[] = [$property, inTenant($property, function () {
            $type = app(CreateRoomType::class)->handle(null, InventoryForm::roomType());
            app(CreateRoom::class)->handle(null, InventoryForm::room($type->id));
            $record = InventoryRecord::query()->where('room_type_id', $type->id)->firstOrFail();
            $record->sold_count = 1;
            $record->save();

            return $type;
        })];
    }

    $this->travelTo(now()->addDays(3));
    $this->artisan('inventory:extend')->assertSuccessful();
    $this->artisan('inventory:extend')->assertSuccessful();

    foreach ($types as [$property, $type]) {
        inTenant($property, function () use ($type) {
            $records = InventoryRecord::query()->where('room_type_id', $type->id)->orderBy('date')->get();

            expect($records)->toHaveCount(369)
                ->and($records->last()->date->toDateString())->toBe('2027-10-11')
                ->and($records->pluck('sellable_count')->unique()->all())->toBe([1])
                ->and($records->first()->sold_count)->toBe(1)
                ->and($records->sum('sold_count'))->toBe(1);
        });
    }
});
