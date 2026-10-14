<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Actions\TakeRoomOutOfService;
use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Inventory\Models\OutOfServicePeriod;
use App\Domains\Inventory\Models\Room;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\Property;
use Inertia\Testing\AssertableInertia as Assert;
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
    expect(Activity::query()->where('description', 'Room out of service refused')->sole()->getExtraProperty('result'))->toBe('refused');
    // Back in service on the 15th, so another period can start that day.
    $this->post(route('out-of-service.store', $room), [...$p, 'starts_on' => '2026-10-15', 'returns_on' => '2026-10-20'])->assertSessionHasNoErrors();

    expect(inTenant($t->a->property, fn () => $room->outOfServicePeriods()->count()))->toBe(2);
});

test('taking a room out of service is refused when fewer rooms would be sellable than are sold, naming the dates', function () {
    $t = twoTenants();
    [$type, $room] = twoRoomsIn($t->a->property);
    seedSold($t->a->property, $type, '2026-10-11', 2);
    seedSold($t->a->property, $type, '2026-10-13', 2);

    $this->actingAs($t->a->owner)
        ->post(route('out-of-service.store', $room), ['_property' => $t->a->property->id, 'starts_on' => '2026-10-10', 'returns_on' => '2026-10-14', 'reason' => 'Leaking shower'])
        ->assertSessionHasErrors(['inventory' => 'DLX would have fewer sellable rooms than are already sold on 2026-10-11, 2026-10-13.']);

    expect(inTenant($t->a->property, fn () => $room->outOfServicePeriods()->count()))->toBe(0)
        ->and(sellableOn($t->a->property, $type, ['2026-10-11']))->toBe(['2026-10-11' => 2]);

    $refusal = Activity::query()->where('event', 'refused')->sole();
    expect($refusal->description)->toBe('Room out of service refused')
        ->and($refusal->getExtraProperty('result'))->toBe('refused');
});

test('deactivating or moving a room is refused when it would strand sold rooms', function () {
    $t = twoTenants();
    [$type, $room] = twoRoomsIn($t->a->property);
    $other = inTenant($t->a->property, fn () => app(CreateRoomType::class)->handle(null, InventoryForm::roomType(['code' => 'STE'])));
    seedSold($t->a->property, $type, '2026-10-20', 2);
    $p = ['_property' => $t->a->property->id];
    $this->actingAs($t->a->owner);

    $this->post(route('rooms.deactivate', $room), $p)
        ->assertSessionHasErrors(['inventory' => 'DLX would have fewer sellable rooms than are already sold on 2026-10-20.']);
    $this->put(route('rooms.move', $room), [...$p, 'room_type_id' => $other->id])
        ->assertSessionHasErrors(['inventory' => 'DLX would have fewer sellable rooms than are already sold on 2026-10-20.']);

    expect(inTenant($t->a->property, fn () => $room->fresh()->only(['room_type_id', 'deactivated_at'])))->toBe(['room_type_id' => $type->id, 'deactivated_at' => null])
        ->and(sellableOn($t->a->property, $type, ['2026-10-20']))->toBe(['2026-10-20' => 2])
        ->and(Activity::query()->where('event', 'refused')->pluck('description')->all())->toBe(['Room deactivation refused', 'Room move refused']);
});

test('a property manager changes a period and ends it early, and sellable follows', function () {
    $t = twoTenants();
    [$type, $room] = twoRoomsIn($t->a->property);
    $period = inTenant($t->a->property, fn () => app(TakeRoomOutOfService::class)->handle(null, $room, ['starts_on' => '2026-10-08', 'returns_on' => '2026-10-20', 'reason' => 'Renovation']));
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager));

    // Started already, so the start stays; ending early means returning on an earlier date.
    $this->put(route('out-of-service.update', $period), ['_property' => $t->a->property->id, 'starts_on' => '2026-10-09', 'returns_on' => '2026-10-10', 'reason' => 'Renovation'])
        ->assertSessionHasErrors(['starts_on' => 'This period has started, so its start date stays.']);
    $this->put(route('out-of-service.update', $period), ['_property' => $t->a->property->id, 'starts_on' => '2026-10-08', 'returns_on' => '2026-10-10', 'reason' => 'Renovation finished early'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('rooms.edit', $room));

    expect(sellableOn($t->a->property, $type, ['2026-10-08', '2026-10-09', '2026-10-10', '2026-10-19']))
        ->toBe(['2026-10-08' => 1, '2026-10-09' => 1, '2026-10-10' => 2, '2026-10-19' => 2]);

    $audit = Activity::query()->where('description', 'Out-of-service period changed')->sole();
    expect($audit->getExtraProperty('old'))->toEqual(['room_id' => $room->id, 'starts_on' => '2026-10-08', 'returns_on' => '2026-10-20', 'reason' => 'Renovation'])
        ->and($audit->getExtraProperty('attributes'))->toEqual(['room_id' => $room->id, 'starts_on' => '2026-10-08', 'returns_on' => '2026-10-10', 'reason' => 'Renovation finished early']);
});

test('lengthening a period is refused when it would strand sold rooms', function () {
    $t = twoTenants();
    [$type, $room] = twoRoomsIn($t->a->property);
    $period = inTenant($t->a->property, fn () => app(TakeRoomOutOfService::class)->handle(null, $room, ['starts_on' => '2026-10-10', 'returns_on' => '2026-10-12', 'reason' => 'Repainting']));
    seedSold($t->a->property, $type, '2026-10-12', 2);

    $this->actingAs($t->a->owner)
        ->put(route('out-of-service.update', $period), ['_property' => $t->a->property->id, 'starts_on' => '2026-10-10', 'returns_on' => '2026-10-13', 'reason' => 'Repainting'])
        ->assertSessionHasErrors(['inventory' => 'DLX would have fewer sellable rooms than are already sold on 2026-10-12.']);

    expect(inTenant($t->a->property, fn () => $period->fresh()->returns_on->toDateString()))->toBe('2026-10-12');
});

test('the daily extension reports a refused room type and still extends every other property', function () {
    $t = twoTenants();
    [$stranded, $room] = twoRoomsIn($t->a->property);
    [$fine] = twoRoomsIn($t->b->property);
    seedSold($t->a->property, $stranded, '2026-10-20', 2);
    // A change made outside the actions, which the extension would now apply.
    inTenant($t->a->property, fn () => $room->forceFill(['deactivated_at' => now()])->save());

    foreach ([$t->a->property, $t->b->property] as $property) {
        $property->update(['business_date' => '2026-10-09']);
    }
    $this->artisan('inventory:extend')
        ->expectsOutputToContain('DLX would have fewer sellable rooms than are already sold on 2026-10-20.')
        ->assertFailed();

    expect(sellableOn($t->b->property, $fine, ['2027-10-09']))->toBe(['2027-10-09' => 2])
        ->and(sellableOn($t->a->property, $stranded, ['2026-10-20']))->toBe(['2026-10-20' => 2]);
});

test('the room page lists its current and upcoming out-of-service periods', function () {
    $t = twoTenants();
    [, $room] = twoRoomsIn($t->a->property);
    inTenant($t->a->property, function () use ($room) {
        app(TakeRoomOutOfService::class)->handle(null, $room, ['starts_on' => '2026-10-20', 'returns_on' => '2026-10-22', 'reason' => 'Repainting']);
        app(TakeRoomOutOfService::class)->handle(null, $room, ['starts_on' => '2026-10-08', 'returns_on' => '2026-10-10', 'reason' => 'Leaking shower']);
        $room->outOfServicePeriods()->create(['starts_on' => '2026-10-01', 'returns_on' => '2026-10-03', 'reason' => 'Ended']);
    });

    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))
        ->get(route('rooms.edit', $room))
        ->assertInertia(fn (Assert $page) => $page
            ->where('today', '2026-10-08')
            ->has('outOfService', 2)
            ->where('outOfService.0.startsOn', '2026-10-08')
            ->where('outOfService.0.returnsOn', '2026-10-10')
            ->where('outOfService.0.reason', 'Leaking shower')
            ->where('outOfService.0.started', true)
            ->where('outOfService.1.reason', 'Repainting')
            ->where('outOfService.1.started', false));
});

test('front desk and auditors cannot take rooms out of service or change periods', function (OrganizationRole $role) {
    $t = twoTenants();
    [$type, $room] = twoRoomsIn($t->a->property);
    $period = inTenant($t->a->property, fn () => app(TakeRoomOutOfService::class)->handle(null, $room, ['starts_on' => '2026-10-10', 'returns_on' => '2026-10-12', 'reason' => 'Repainting']));
    $form = ['_property' => $t->a->property->id, 'starts_on' => '2026-10-20', 'returns_on' => '2026-10-22', 'reason' => 'Nope'];

    $this->actingAs($t->a->member($role))->post(route('out-of-service.store', $room), $form)->assertForbidden();
    $this->put(route('out-of-service.update', $period), $form)->assertForbidden();

    expect(inTenant($t->a->property, fn () => OutOfServicePeriod::query()->sole()->details()))
        ->toBe(['room_id' => $room->id, 'starts_on' => '2026-10-10', 'returns_on' => '2026-10-12', 'reason' => 'Repainting']);
})->with([OrganizationRole::FrontDesk, OrganizationRole::Auditor]);

test('out-of-service dates follow the stored business date when night audit lags the clock', function () {
    $t = twoTenants();
    $t->a->property->update(['business_date' => '2026-10-07']);
    [$type, $room] = twoRoomsIn($t->a->property);

    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))
        ->post(route('out-of-service.store', $room), ['_property' => $t->a->property->id, 'starts_on' => '2026-10-07', 'returns_on' => '2026-10-08', 'reason' => 'Leaking shower'])
        ->assertSessionHasNoErrors();

    expect(sellableOn($t->a->property, $type, ['2026-10-07', '2026-10-08']))->toBe(['2026-10-07' => 1, '2026-10-08' => 2]);

    $this->get(route('rooms.edit', $room))->assertInertia(fn (Assert $page) => $page
        ->where('today', '2026-10-07')
        ->has('outOfService', 1)
        ->where('outOfService.0.started', true));
});
