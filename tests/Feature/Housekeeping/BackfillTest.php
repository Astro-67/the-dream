<?php

namespace Tests\Feature\Housekeeping;

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Models\Room;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\InventoryForm;
use Tests\TestCase;

class BackfillTest extends TestCase
{
    use DatabaseMigrations;

    public function test_existing_rooms_in_different_properties_are_backfilled_dirty(): void
    {
        $t = \twoTenants();
        $migration = require database_path('migrations/2026_10_21_000064_housekeeping_create_room_statuses.php');
        $migration->down();
        $rooms = [];
        foreach ([$t->a->property, $t->a->otherProperty, $t->b->property] as $property) {
            $rooms[] = [$property, \inTenant($property, function () use ($t) {
                $type = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());

                return Room::query()->create(InventoryForm::room($type->id));
            })];
        }
        $migration->up();
        foreach ($rooms as [$property, $room]) {
            \inTenant($property, function () use ($room) {
                $this->assertSame(HousekeepingStatus::Dirty, RoomStatus::query()->where('room_id', $room->id)->sole()->status);
            });
        }
    }
}
