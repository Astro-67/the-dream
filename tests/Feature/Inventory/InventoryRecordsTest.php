<?php

use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Models\InventoryRecord;
use Tests\Support\InventoryForm;

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
