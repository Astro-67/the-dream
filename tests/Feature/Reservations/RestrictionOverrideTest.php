<?php

use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Rates\Models\RateRestriction;
use App\Domains\Rates\StayRestrictions;
use Tests\Support\BookingForm;

test('stay restrictions list arrival rules and stop sell on each occupied night', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        RateRestriction::query()->create(['rate_plan_id' => $details['rate_plan_id'], 'room_type_id' => $details['room_type_id'], 'date' => '2026-12-10', 'min_stay' => 3, 'closed_to_arrival' => true, 'stop_sell' => true]);
        RateRestriction::query()->create(['rate_plan_id' => $details['rate_plan_id'], 'room_type_id' => $details['room_type_id'], 'date' => '2026-12-11', 'min_stay' => 5, 'closed_to_arrival' => true, 'stop_sell' => true]);
        RateRestriction::query()->create(['rate_plan_id' => $details['rate_plan_id'], 'room_type_id' => $details['room_type_id'], 'date' => '2026-12-12', 'min_stay' => 9, 'closed_to_arrival' => true, 'stop_sell' => true]);
        expect(app(StayRestrictions::class)->warnings(RatePlan::query()->findOrFail($details['rate_plan_id']), RoomType::query()->findOrFail($details['room_type_id']), $details['arrival'], $details['departure']))->toBe([
            'Minimum stay on 2026-12-10 is 3 nights; this stay is 2 nights.',
            'Closed to arrival on 2026-12-10.',
            'Stop-sell on 2026-12-10.',
            'Stop-sell on 2026-12-11.',
        ]);
    });
});
