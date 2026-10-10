<?php

namespace Tests\Support;

use App\Domains\Guests\Actions\CreateBooker;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Rates\Actions\CreateRatePlan;
use App\Domains\Rates\Actions\SetRatePrices;
use App\Models\User;

final class BookingForm
{
    /** @return array<string, mixed> */
    public static function details(User $owner): array
    {
        $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType());
        app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id));
        $plan = app(CreateRatePlan::class)->handle($owner, ['name' => 'Breakfast', 'code' => 'BB', 'currency' => 'USD', 'room_type_ids' => [$type->id]]);
        app(SetRatePrices::class)->handle($owner, $plan, ['room_type_id' => $type->id, 'from' => '2026-12-10', 'to' => '2026-12-11', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'base_price' => 10000, 'extra_adult' => 2500, 'extra_child' => 1000]);
        $booker = app(CreateBooker::class)->handle($owner, ['name' => 'Travel Booker']);

        return ['room_type_id' => $type->id, 'rate_plan_id' => $plan->id, 'booker_id' => $booker->id, 'arrival' => '2026-12-10', 'departure' => '2026-12-12', 'adults' => 2, 'children' => 1, 'source' => 'phone'];
    }
}
