<?php

use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Rates\Actions\CreateRatePlan;
use App\Domains\Rates\Actions\SetRatePrices;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\InventoryForm;

test('bulk prices select weekdays and audit once with the range and minor units', function () {
    $t = twoTenants();
    [$room, $plan] = inTenant($t->a->property, function () use ($t) {
        $room = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());

        return [$room, app(CreateRatePlan::class)->handle($t->a->owner, ['name' => 'Breakfast', 'code' => 'BB', 'currency' => 'USD', 'room_type_ids' => [$room->id]])];
    });
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->put(route('rate-plans.prices.update', $plan), [
        '_property' => $t->a->property->id, 'room_type_id' => $room->id,
        'from' => '2026-10-05', 'to' => '2026-10-11', 'weekdays' => [1, 5],
        'base_price' => 12000, 'extra_adult' => 3000, 'extra_child' => 1500,
    ])->assertSessionHasNoErrors()->assertRedirect(route('rate-plans.prices', $plan));
    $this->get(route('rate-plans.prices', $plan))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('rates/prices')->has('prices', 2)->where('prices.0.date', '2026-10-05')->where('prices.1.date', '2026-10-09')->where('prices.0.base_price', 12000));
    $this->put(route('rate-plans.prices.update', $plan), ['_property' => $t->a->property->id, 'room_type_id' => $room->id, 'from' => 'soon', 'to' => '2026-10-11', 'weekdays' => [1], 'base_price' => 1, 'extra_adult' => 0, 'extra_child' => 0])
        ->assertSessionHasErrors('from');
    $this->put(route('rate-plans.prices.update', $plan), ['_property' => $t->a->property->id, 'room_type_id' => $room->id, 'from' => '2026-01-01', 'to' => '2028-01-02', 'weekdays' => [1], 'base_price' => 1, 'extra_adult' => 0, 'extra_child' => 0])
        ->assertSessionHasErrors('to');
    $audit = Activity::query()->where('description', 'Rate prices updated')->sole();
    expect($audit->getExtraProperty('attributes'))->toEqual(['base_price' => 12000, 'extra_adult' => 3000, 'extra_child' => 1500])
        ->and($audit->getExtraProperty('from'))->toBe('2026-10-05')->and($audit->getExtraProperty('to'))->toBe('2026-10-11');
});

test('a property manager creates a rate plan for a room type and sees it listed', function () {
    $t = twoTenants();
    $room = inTenant($t->a->property, fn () => app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType()));
    $response = $this->actingAs($t->a->member(OrganizationRole::PropertyManager))
        ->post(route('rate-plans.store'), ['_property' => $t->a->property->id, 'name' => 'Bed and breakfast', 'code' => 'BB', 'currency' => 'USD', 'room_type_ids' => [$room->id]])
        ->assertSessionHasNoErrors();
    $plan = inTenant($t->a->property, fn () => RatePlan::query()->sole());
    $response->assertRedirect(route('rate-plans.prices', $plan));
    $this->get(route('rate-plans.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('rates/index')->has('ratePlans', 1)->where('ratePlans.0.code', 'BB')->where('ratePlans.0.currency', 'USD')->where('canManage', true));
});

test('front desk previews a quote and sees nightly prices without managing them', function () {
    $t = twoTenants();
    [$room, $plan] = inTenant($t->a->property, function () use ($t) {
        $room = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());
        $plan = app(CreateRatePlan::class)->handle($t->a->owner, ['name' => 'Breakfast', 'code' => 'BB', 'currency' => 'USD', 'room_type_ids' => [$room->id]]);
        app(SetRatePrices::class)->handle($t->a->owner, $plan, ['room_type_id' => $room->id, 'from' => '2026-12-01', 'to' => '2026-12-10', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'base_price' => 10000, 'extra_adult' => 2500, 'extra_child' => 1000]);

        return [$room, $plan];
    });
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));

    $this->get(route('rate-plans.quote', [$plan, 'room_type_id' => $room->id, 'arrival' => '2026-12-09', 'departure' => '2026-12-11', 'adults' => 2, 'children' => 1]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('rates/quote')
        ->where('quote.currency', 'USD')->where('quote.total', 22000)->has('quote.nights', 2)->where('quote.nights.0.extra_children', 1000)->where('quote.unquotable', null)
        ->where('canManage', false));
    $this->get(route('rate-plans.quote', $plan))->assertOk()->assertInertia(fn (Assert $page) => $page->where('quote', null));
    $this->get(route('rate-plans.quote', [$plan, 'room_type_id' => $room->id, 'arrival' => 'soon']))->assertSessionHasErrors('arrival');
    $this->put(route('rate-plans.prices.update', $plan), ['_property' => $t->a->property->id])->assertForbidden();
});

test('only owners, group operations and property managers manage rates', function (OrganizationRole $role, bool $manages) {
    $t = twoTenants();
    $room = inTenant($t->a->property, fn () => app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType()));
    $response = $this->actingAs($role === OrganizationRole::Owner ? $t->a->owner : $t->a->member($role))
        ->post(route('rate-plans.store'), ['_property' => $t->a->property->id, 'name' => 'Bed and breakfast', 'code' => 'BB', 'currency' => 'USD', 'room_type_ids' => [$room->id]]);

    $manages ? $response->assertSessionHasNoErrors()->assertRedirect() : $response->assertForbidden();
})->with([
    'owner' => [OrganizationRole::Owner, true],
    'group operations manager' => [OrganizationRole::GroupOperationsManager, true],
    'property manager' => [OrganizationRole::PropertyManager, true],
    'front desk' => [OrganizationRole::FrontDesk, false],
    'finance' => [OrganizationRole::Finance, false],
    'housekeeping' => [OrganizationRole::Housekeeping, false],
    'auditor' => [OrganizationRole::Auditor, false],
]);

test('a rate plan\'s currency must be one the property\'s jurisdiction allows', function () {
    $t = twoTenants();
    $room = inTenant($t->a->property, fn () => app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType()));

    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))
        ->post(route('rate-plans.store'), ['_property' => $t->a->property->id, 'name' => 'Pounds', 'code' => 'GBP', 'currency' => 'GBP', 'room_type_ids' => [$room->id]])
        ->assertSessionHasErrors('currency');
});

test('a derived plan takes its base plan\'s currency and room types and derives one level only', function () {
    $t = twoTenants();
    [$room, $other, $base] = inTenant($t->a->property, function () use ($t) {
        $room = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());
        $other = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType(['name' => 'Garden Twin', 'code' => 'GDN']));

        $base = app(CreateRatePlan::class)->handle($t->a->owner, ['name' => 'Breakfast', 'code' => 'BB', 'currency' => 'EUR', 'room_type_ids' => [$room->id]]);
        app(SetRatePrices::class)->handle($t->a->owner, $base, ['room_type_id' => $room->id, 'from' => '2026-12-01', 'to' => '2026-12-01', 'weekdays' => [2], 'base_price' => 10000, 'extra_adult' => 2000, 'extra_child' => 1000]);

        return [$room, $other, $base];
    });
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager));
    $derived = ['_property' => $t->a->property->id, 'name' => 'Non-refundable', 'code' => 'NR', 'base_rate_plan_id' => $base->id, 'adjustment_kind' => 'percent', 'adjustment' => -10];

    // Currency and room types sent alongside a base are ignored, never converted or widened.
    $response = $this->post(route('rate-plans.store'), [...$derived, 'currency' => 'USD', 'room_type_ids' => [$other->id]])->assertSessionHasNoErrors();
    $this->get(route('rate-plans.index'))->assertInertia(fn (Assert $page) => $page
        ->where('ratePlans.1.code', 'NR')->where('ratePlans.1.currency', 'EUR')->where('ratePlans.1.roomTypes.0.id', $room->id)->has('ratePlans.1.roomTypes', 1)
        ->where('ratePlans.1.base', ['id' => $base->id, 'name' => 'Breakfast', 'adjustmentKind' => 'percent', 'adjustment' => -10]));
    $nonRefundable = inTenant($t->a->property, fn () => RatePlan::query()->where('code', 'NR')->sole());
    $response->assertRedirect(route('rate-plans.prices', $nonRefundable));

    $this->post(route('rate-plans.store'), [...$derived, 'code' => 'NR2', 'base_rate_plan_id' => $nonRefundable->id])->assertSessionHasErrors('base_rate_plan_id');
    $this->post(route('rate-plans.store'), [...$derived, 'code' => 'NR3', 'adjustment_kind' => 'markup'])->assertSessionHasErrors('adjustment_kind');
    $this->post(route('rate-plans.store'), [...$derived, 'code' => 'NR4', 'adjustment' => -101])->assertSessionHasErrors('adjustment');
    $this->post(route('rate-plans.store'), [...$derived, 'code' => 'NR5', 'adjustment' => 2.5])->assertSessionHasErrors('adjustment');
    $this->put(route('rate-plans.prices.update', $nonRefundable), ['_property' => $t->a->property->id, 'room_type_id' => $room->id, 'from' => '2026-12-01', 'to' => '2026-12-01', 'weekdays' => [2], 'base_price' => 1, 'extra_adult' => 0, 'extra_child' => 0])
        ->assertSessionHasErrors('room_type_id');
    $this->get(route('rate-plans.prices', $nonRefundable))->assertInertia(fn (Assert $page) => $page
        ->where('canManage', false)->where('base.name', 'Breakfast')
        ->where('prices', [['room_type_id' => $room->id, 'date' => '2026-12-01', 'base_price' => 9000, 'extra_adult' => 1800, 'extra_child' => 900]]));
});

test('a base plan with active derived plans can be neither deactivated nor deleted until they are handled', function () {
    $t = twoTenants();
    [$base, $derived] = inTenant($t->a->property, function () use ($t) {
        $room = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType());
        $base = app(CreateRatePlan::class)->handle($t->a->owner, ['name' => 'Breakfast', 'code' => 'BB', 'currency' => 'USD', 'room_type_ids' => [$room->id]]);

        return [$base, app(CreateRatePlan::class)->handle($t->a->owner, ['name' => 'Non-refundable', 'code' => 'NR', 'base_rate_plan_id' => $base->id, 'adjustment_kind' => 'percent', 'adjustment' => -10])];
    });
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager));
    $deactivate = fn (RatePlan $plan) => $this->post(route('rate-plans.deactivate', $plan), ['_property' => $t->a->property->id]);

    $deactivate($base)->assertSessionHasErrors(['plan' => 'Deactivate the plans derived from Breakfast first: Non-refundable.']);
    expect(Activity::query()->where('description', 'Rate plan deactivation refused')->sole()->getExtraProperty('result'))->toBe('refused');
    expect(fn () => inTenant($t->a->property, fn () => $base->delete()))->toThrow(QueryException::class);

    $deactivate($derived)->assertSessionHasNoErrors()->assertRedirect(route('rate-plans.index'));
    $deactivate($base)->assertSessionHasNoErrors();
    $this->get(route('rate-plans.index'))->assertInertia(fn (Assert $page) => $page->where('ratePlans.0.active', false)->where('ratePlans.1.active', false));
    // An inactive plan can't become a base.
    $this->post(route('rate-plans.store'), ['_property' => $t->a->property->id, 'name' => 'Late', 'code' => 'LT', 'base_rate_plan_id' => $base->id, 'adjustment_kind' => 'percent', 'adjustment' => 5])
        ->assertSessionHasErrors('base_rate_plan_id');
    expect(Activity::query()->where('description', 'Rate plan deactivated')->count())->toBe(2);
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->post(route('rate-plans.deactivate', $base), ['_property' => $t->a->property->id])->assertForbidden();
});

test('a rates manager edits plan details and room types with an audit', function () {
    $t = twoTenants();
    [$plan, $other] = inTenant($t->a->property, function () {
        $room = app(CreateRoomType::class)->handle(null, InventoryForm::roomType());
        $other = app(CreateRoomType::class)->handle(null, InventoryForm::roomType(['code' => 'OTHER']));

        return [app(CreateRatePlan::class)->handle(null, ['name' => 'Breakfast', 'code' => 'BB', 'currency' => 'USD', 'room_type_ids' => [$room->id]]), $other];
    });
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->get(route('rate-plans.edit', $plan))->assertOk()->assertInertia(fn (Assert $page) => $page->component('rates/edit')->where('plan.name', 'Breakfast'));
    $form = ['_property' => $t->a->property->id, 'name' => 'Room only', 'code' => 'RO', 'room_type_ids' => [$other->id]];
    $this->put(route('rate-plans.update', $plan), $form)->assertSessionHasNoErrors()->assertRedirect(route('rate-plans.index'));
    $this->get(route('rate-plans.index'))->assertInertia(fn (Assert $page) => $page->where('ratePlans.0.name', 'Room only')->where('ratePlans.0.code', 'RO')->where('ratePlans.0.roomTypes.0.id', $other->id)->where('ratePlans.0.currency', 'USD'));
    expect(Activity::query()->where('description', 'Rate plan updated')->sole()->getExtraProperty('attributes'))->toMatchArray(['name' => 'Room only', 'code' => 'RO', 'room_type_ids' => [$other->id]]);
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get(route('rate-plans.edit', $plan))->assertForbidden();
    $this->put(route('rate-plans.update', $plan), $form)->assertForbidden();
});

test('derived plans keep base currency and room types when either plan is edited', function () {
    $t = twoTenants();
    [$base, $derived, $room, $other] = inTenant($t->a->property, function () {
        $room = app(CreateRoomType::class)->handle(null, InventoryForm::roomType());
        $other = app(CreateRoomType::class)->handle(null, InventoryForm::roomType(['code' => 'OTHER']));
        $base = app(CreateRatePlan::class)->handle(null, ['name' => 'Breakfast', 'code' => 'BB', 'currency' => 'EUR', 'room_type_ids' => [$room->id]]);
        $derived = app(CreateRatePlan::class)->handle(null, ['name' => 'Derived', 'code' => 'D', 'base_rate_plan_id' => $base->id, 'adjustment_kind' => 'percent', 'adjustment' => -10]);

        return [$base, $derived, $room, $other];
    });
    $this->actingAs($t->a->owner)->put(route('rate-plans.update', $derived), ['_property' => $t->a->property->id, 'name' => 'Renamed', 'code' => 'D', 'currency' => 'USD', 'room_type_ids' => [$other->id]])->assertSessionHasNoErrors();
    expect(inTenant($t->a->property, fn () => $derived->fresh()->roomTypes()->pluck('room_types.id')->all()))->toBe([$room->id]);
    $this->put(route('rate-plans.update', $base), ['_property' => $t->a->property->id, 'name' => 'Breakfast', 'code' => 'BB', 'room_type_ids' => [$other->id]])->assertSessionHasNoErrors();
    expect(inTenant($t->a->property, fn () => $derived->fresh()->roomTypes()->pluck('room_types.id')->all()))->toBe([$other->id])
        ->and(inTenant($t->a->property, fn () => $derived->fresh()->currency->value))->toBe('EUR');
});

test('a rates manager sets a cancellation policy and no-show fee on a plan with an audit', function () {
    $t = twoTenants();
    $plan = inTenant($t->a->property, function () {
        $room = app(CreateRoomType::class)->handle(null, InventoryForm::roomType());

        return app(CreateRatePlan::class)->handle(null, ['name' => 'Breakfast', 'code' => 'BB', 'currency' => 'USD', 'room_type_ids' => [$room->id]]);
    });
    $policy = ['cancellation_free_days' => 7, 'cancellation_charge' => 'percent', 'cancellation_percent' => 50, 'no_show_charge' => 'first_night', 'no_show_percent' => null];
    $form = ['_property' => $t->a->property->id, 'name' => 'Breakfast', 'code' => 'BB', 'room_type_ids' => inTenant($t->a->property, fn () => $plan->roomTypes()->pluck('room_types.id')->all()), ...$policy];
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->put(route('rate-plans.update', $plan), $form)->assertSessionHasNoErrors();

    $this->get(route('rate-plans.edit', $plan))->assertInertia(fn (Assert $page) => $page->where('plan.cancellation_free_days', 7)->where('plan.cancellation_charge', 'percent')
        ->where('plan.cancellation_percent', 50)->where('plan.no_show_charge', 'first_night')->where('plan.no_show_percent', null));
    expect(Activity::query()->where('description', 'Rate plan updated')->sole()->getExtraProperty('attributes'))->toMatchArray($policy);

    $this->put(route('rate-plans.update', $plan), [...$form, 'cancellation_charge' => 'all', 'no_show_charge' => 'percent', 'cancellation_free_days' => -1])
        ->assertSessionHasErrors(['cancellation_charge', 'no_show_percent', 'cancellation_free_days']);
    $this->put(route('rate-plans.update', $plan), [...$form, 'cancellation_percent' => 101])->assertSessionHasErrors(['cancellation_percent']);
});

test('a rates manager creates a plan with its cancellation policy and no-show fee', function (bool $derived) {
    $t = twoTenants();
    [$room, $base] = inTenant($t->a->property, function () {
        $room = app(CreateRoomType::class)->handle(null, InventoryForm::roomType());

        return [$room, app(CreateRatePlan::class)->handle(null, ['name' => 'Base', 'code' => 'BASE', 'currency' => 'USD', 'room_type_ids' => [$room->id]])];
    });
    $policy = ['cancellation_free_days' => 7, 'cancellation_charge' => 'percent', 'cancellation_percent' => 50, 'no_show_charge' => 'first_night', 'no_show_percent' => null];
    $form = ['_property' => $t->a->property->id, 'name' => 'Breakfast', 'code' => 'BB', ...$policy,
        ...($derived ? ['base_rate_plan_id' => $base->id, 'adjustment_kind' => 'percent', 'adjustment' => 10] : ['currency' => 'USD', 'room_type_ids' => [$room->id]])];
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager));
    $this->post(route('rate-plans.store'), [...$form, 'no_show_charge' => 'percent', 'no_show_percent' => null, 'cancellation_free_days' => -1])
        ->assertSessionHasErrors(['no_show_percent', 'cancellation_free_days']);
    $response = $this->post(route('rate-plans.store'), [...$form, 'no_show_percent' => 80])->assertSessionHasNoErrors();
    $plan = inTenant($t->a->property, fn () => RatePlan::query()->where('code', 'BB')->sole());
    $response->assertRedirect(route('rate-plans.prices', $plan));
    $this->get(route('rate-plans.edit', $plan))->assertInertia(fn (Assert $page) => $page
        ->where('plan.cancellation_free_days', 7)->where('plan.cancellation_charge', 'percent')->where('plan.cancellation_percent', 50)
        ->where('plan.no_show_charge', 'first_night')->where('plan.no_show_percent', null));
    expect(Activity::query()->where('description', 'Rate plan created')->where('subject_id', $plan->id)->sole()->getExtraProperty('attributes'))->toMatchArray($policy);
})->with(['base' => false, 'derived' => true]);
