<?php

use App\Domains\Housekeeping\Actions\AssignRoom;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\Assignment;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Housekeeping\Models\StatusChange;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Tests\Support\InventoryForm;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

$bravoRoom = fn (TwoTenants $t) => inTenant($t->b->property, function () use ($t) {
    $type = app(CreateRoomType::class)->handle($t->b->owner, InventoryForm::roomType(['name' => 'Bravo Secret Suite', 'code' => 'BRVX']));

    return app(CreateRoom::class)->handle($t->b->owner, InventoryForm::room($type->id, ['number' => 'B-901']));
});

$bravoAssignment = function (TwoTenants $t) use ($bravoRoom) {
    $room = $bravoRoom($t);

    return inTenant($t->b->property, fn () => app(AssignRoom::class)->handle($t->b->owner, $room, $t->b->member(OrganizationRole::Housekeeping)->id, '2026-10-12'));
};

return [
    'housekeeping.assignments.index' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoAssignment) {
        $bravoAssignment($t);

        return $this->actingAs($t->a->owner)->get(route('housekeeping.assignments.index', ['date' => '2026-10-12']))->assertOk()->assertDontSee('B-901');
    }),
    'housekeeping.my-rooms' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoAssignment) {
        $bravoAssignment($t);

        return $this->actingAs($t->a->member(OrganizationRole::Housekeeping))->get(route('housekeeping.my-rooms', ['date' => '2026-10-12']))->assertOk()->assertDontSee('B-901');
    }),
    'housekeeping.assignments.store' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoRoom) {
        $room = $bravoRoom($t);
        $responses = [];
        foreach ([$t->a->property->id, $t->b->property->id] as $intent) {
            $response = $this->actingAs($t->a->owner)->post(route('housekeeping.assignments.store'), ['_property' => $intent, 'business_date' => '2026-10-12', 'room_id' => $room->id, 'housekeeper_id' => $t->a->member(OrganizationRole::Housekeeping)->id]);
            $intent === $t->a->property->id ? $response->assertSessionHasErrors('room_id') : $response->assertForbidden();
            $responses[] = $response;
        }
        expect(inTenant($t->b->property, fn () => Assignment::query()->count()))->toBe(0);

        return $responses;
    }),
    'housekeeping.assignments.destroy' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoAssignment) {
        $assignment = $bravoAssignment($t);
        $responses = [];
        foreach ([$t->a->property->id, $t->b->property->id] as $intent) {
            $responses[] = $this->actingAs($t->a->owner)->delete(route('housekeeping.assignments.destroy', $assignment->id), ['_property' => $intent])->assertStatus($intent === $t->a->property->id ? 404 : 403);
        }
        expect(inTenant($t->b->property, fn () => $assignment->fresh()))->not->toBeNull();

        return $responses;
    }),
    'housekeeping.assignments.complete' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoAssignment) {
        $assignment = $bravoAssignment($t);
        $responses = [];
        foreach ([$t->a->property->id, $t->b->property->id] as $intent) {
            $responses[] = $this->actingAs($t->a->member(OrganizationRole::Housekeeping))->post(route('housekeeping.assignments.complete', $assignment->id), ['_property' => $intent])->assertStatus($intent === $t->a->property->id ? 404 : 403);
        }
        expect(inTenant($t->b->property, fn () => $assignment->fresh()->completed_at))->toBeNull();
        expect(inTenant($t->b->property, fn () => RoomStatus::query()->sole()->status))->toBe(HousekeepingStatus::Dirty);
        expect(inTenant($t->b->property, fn () => StatusChange::query()->count()))->toBe(0);

        return $responses;
    }),
    'housekeeping.index' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoRoom) {
        $bravoRoom($t);
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
                ->actingAs($member)->get(route('housekeeping.index'))->assertDontSee('Bravo Secret Suite')->assertDontSee('BRVX')->assertDontSee('B-901');
        }

        return $responses;
    }),
    'housekeeping.history' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoRoom) {
        $room = $bravoRoom($t);

        return $this->actingAs($t->a->owner)->get(route('housekeeping.history', $room->id))->assertNotFound();
    }),
    'housekeeping.override-ready' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoRoom) {
        $room = $bravoRoom($t);
        $before = inTenant($t->b->property, fn () => RoomStatus::query()->sole()->getAttributes());
        $responses = [];
        foreach ([$t->a->property->id, $t->b->property->id] as $intent) {
            $response = $this->actingAs($t->a->owner)->post(route('housekeeping.override-ready', $room->id), ['_property' => $intent, 'reason' => 'Attempt']);
            $response->assertStatus($intent === $t->a->property->id ? 404 : 403);
            $responses[] = $response;
        }
        expect(inTenant($t->b->property, fn () => RoomStatus::query()->sole()->getAttributes()))->toBe($before);
        expect(inTenant($t->b->property, fn () => StatusChange::query()->count()))->toBe(0);

        return $responses;
    }),
    'housekeeping.status' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoRoom) {
        $room = $bravoRoom($t);
        $before = inTenant($t->b->property, fn () => RoomStatus::query()->sole()->getAttributes());
        $responses = [];
        foreach ([$t->a->property->id, $t->b->property->id] as $intent) {
            $response = $this->actingAs($t->a->owner)->patch(route('housekeeping.status', $room->id), ['_property' => $intent, 'status' => 'clean', 'reason' => 'Attempt']);
            $response->assertStatus($intent === $t->a->property->id ? 404 : 403);
            $responses[] = $response;
        }
        expect(inTenant($t->b->property, fn () => RoomStatus::query()->sole()->getAttributes()))->toBe($before);
        expect(inTenant($t->b->property, fn () => StatusChange::query()->count()))->toBe(0);

        return $responses;
    }),
];
