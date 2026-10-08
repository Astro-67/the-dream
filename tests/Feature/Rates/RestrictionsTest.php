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

test('front desk sees restrictions but only rates.manage roles change them', function (OrganizationRole $role, bool $manages) {
    $t = twoTenants();
    [$room, $plan] = inTenant($t->a->property, function () use ($t) {
        $room = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());

        return [$room, app(CreateRatePlan::class)->handle($t->a->owner, ['name' => 'Breakfast', 'code' => 'BB', 'currency' => 'USD', 'room_type_ids' => [$room->id]])];
    });
    $this->actingAs($role === OrganizationRole::Owner ? $t->a->owner : $t->a->member($role));
    $response = $this->put(route('rate-plans.restrictions.update', $plan), ['_property' => $t->a->property->id, 'room_type_id' => $room->id, 'from' => '2026-12-01', 'to' => '2026-12-01', 'weekdays' => [2], 'min_stay' => 3, 'stop_sell' => '1']);

    $manages ? $response->assertSessionHasNoErrors() : $response->assertForbidden();
    expect(Activity::query()->where('description', 'Restrictions updated')->count())->toBe($manages ? 1 : 0);
    if ($role === OrganizationRole::FrontDesk) {
        $this->get(route('rate-plans.restrictions', $plan))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canManage', false));
    }
})->with([
    'owner' => [OrganizationRole::Owner, true],
    'group operations manager' => [OrganizationRole::GroupOperationsManager, true],
    'property manager' => [OrganizationRole::PropertyManager, true],
    'front desk' => [OrganizationRole::FrontDesk, false],
    'finance' => [OrganizationRole::Finance, false],
]);

test('a later edit replaces restrictions on its dates, unchecked flags clear, and bad input is refused', function () {
    $t = twoTenants();
    [$room, $other, $plan] = inTenant($t->a->property, function () use ($t) {
        $room = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());
        $other = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType(['name' => 'Garden Twin', 'code' => 'GDN']));

        return [$room, $other, app(CreateRatePlan::class)->handle($t->a->owner, ['name' => 'Breakfast', 'code' => 'BB', 'currency' => 'USD', 'room_type_ids' => [$room->id]])];
    });
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager));
    $edit = fn (array $values) => $this->put(route('rate-plans.restrictions.update', $plan), ['_property' => $t->a->property->id, 'room_type_id' => $room->id, 'from' => '2026-12-01', 'to' => '2026-12-02', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'min_stay' => 1, ...$values]);

    $edit(['min_stay' => 3, 'closed_to_arrival' => '1', 'stop_sell' => '1'])->assertSessionHasNoErrors();
    $edit(['to' => '2026-12-01', 'min_stay' => 2])->assertSessionHasNoErrors();
    $this->get(route('rate-plans.restrictions', $plan))->assertInertia(fn (Assert $page) => $page->where('restrictions', [
        ['room_type_id' => $room->id, 'date' => '2026-12-01', 'min_stay' => 2, 'closed_to_arrival' => false, 'stop_sell' => false],
        ['room_type_id' => $room->id, 'date' => '2026-12-02', 'min_stay' => 3, 'closed_to_arrival' => true, 'stop_sell' => true],
    ]));

    $edit(['min_stay' => 0])->assertSessionHasErrors('min_stay');
    $edit(['min_stay' => 366])->assertSessionHasErrors('min_stay');
    $edit(['stop_sell' => 'maybe'])->assertSessionHasErrors('stop_sell');
    $edit(['room_type_id' => $other->id])->assertSessionHasErrors('room_type_id');
    $edit(['from' => '2026-12-03'])->assertSessionHasErrors('to');
    $edit(['to' => '2029-01-01'])->assertSessionHasErrors('to');
    $edit(['weekdays' => [8]])->assertSessionHasErrors('weekdays.0');
});

test('a derived plan carries its own restrictions, independent of its base plan', function () {
    $t = twoTenants();
    [$room, $base, $derived] = inTenant($t->a->property, function () use ($t) {
        $room = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());
        $base = app(CreateRatePlan::class)->handle($t->a->owner, ['name' => 'Breakfast', 'code' => 'BB', 'currency' => 'USD', 'room_type_ids' => [$room->id]]);

        return [$room, $base, app(CreateRatePlan::class)->handle($t->a->owner, ['name' => 'Non-refundable', 'code' => 'NR', 'base_rate_plan_id' => $base->id, 'adjustment_kind' => 'percent', 'adjustment' => -10])];
    });
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager));
    $this->put(route('rate-plans.restrictions.update', $base), ['_property' => $t->a->property->id, 'room_type_id' => $room->id, 'from' => '2026-12-01', 'to' => '2026-12-01', 'weekdays' => [2], 'min_stay' => 2])->assertSessionHasNoErrors();
    $this->put(route('rate-plans.restrictions.update', $derived), ['_property' => $t->a->property->id, 'room_type_id' => $room->id, 'from' => '2026-12-01', 'to' => '2026-12-01', 'weekdays' => [2], 'min_stay' => 5, 'stop_sell' => '1'])->assertSessionHasNoErrors();

    $this->get(route('rate-plans.restrictions', $base))->assertInertia(fn (Assert $page) => $page->where('restrictions.0.min_stay', 2)->where('restrictions.0.stop_sell', false));
    $this->get(route('rate-plans.restrictions', $derived))->assertInertia(fn (Assert $page) => $page->where('canManage', true)->has('restrictions', 1)->where('restrictions.0.min_stay', 5)->where('restrictions.0.stop_sell', true));
});
