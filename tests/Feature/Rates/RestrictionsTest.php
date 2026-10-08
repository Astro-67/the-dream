<?php

use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Rates\Actions\CreateRatePlan;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\InventoryForm;

test('a property manager bulk-sets restrictions on chosen weekdays and they are audited once with the range and values', function () {
    $t = twoTenants();
    [$room, $plan] = inTenant($t->a->property, function () use ($t) {
        $room = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());

        return [$room, app(CreateRatePlan::class)->handle($t->a->owner, ['name' => 'Breakfast', 'code' => 'BB', 'currency' => 'USD', 'room_type_ids' => [$room->id]])];
    });
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->put(route('rate-plans.restrictions.update', $plan), [
        '_property' => $t->a->property->id, 'room_type_id' => $room->id,
        'from' => '2026-10-05', 'to' => '2026-10-11', 'weekdays' => [5, 6],
        'min_stay' => 2, 'closed_to_arrival' => true, 'stop_sell' => false,
    ])->assertSessionHasNoErrors()->assertRedirect(route('rate-plans.restrictions', $plan));

    $this->get(route('rate-plans.restrictions', $plan))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('rates/restrictions')->where('canManage', true)
        ->where('restrictions', [
            ['room_type_id' => $room->id, 'date' => '2026-10-09', 'min_stay' => 2, 'closed_to_arrival' => true, 'stop_sell' => false],
            ['room_type_id' => $room->id, 'date' => '2026-10-10', 'min_stay' => 2, 'closed_to_arrival' => true, 'stop_sell' => false],
        ]));
    $audit = Activity::query()->where('description', 'Restrictions updated')->sole();
    expect($audit->getExtraProperty('attributes'))->toEqual(['min_stay' => 2, 'closed_to_arrival' => true, 'stop_sell' => false])
        ->and($audit->getExtraProperty('from'))->toBe('2026-10-05')->and($audit->getExtraProperty('to'))->toBe('2026-10-11')
        ->and($audit->getExtraProperty('weekdays'))->toBe([5, 6])->and($audit->getExtraProperty('room_type_id'))->toBe($room->id);
});
