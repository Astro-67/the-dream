<?php

use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Housekeeping\Models\StatusChange;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use Tests\Support\InventoryForm;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

$bravoRoom = fn (TwoTenants $t) => inTenant($t->b->property, function () use ($t) {
    $type = app(CreateRoomType::class)->handle($t->b->owner, InventoryForm::roomType(['name' => 'Bravo Secret Suite', 'code' => 'BRVX']));

    return app(CreateRoom::class)->handle($t->b->owner, InventoryForm::room($type->id, ['number' => 'B-901']));
});

return [
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
        foreach (['clean', 'cleaned', 'inspection_required', 'dirty'] as $target) {
            foreach ([$t->a->property->id, $t->b->property->id] as $intent) {
                $response = $this->actingAs($t->a->owner)->patch(route('housekeeping.status', $room->id), ['_property' => $intent, 'status' => $target, 'reason' => 'Attempt']);
                $response->assertStatus($intent === $t->a->property->id ? 404 : 403);
                $responses[] = $response;
            }
        }
        expect(inTenant($t->b->property, fn () => RoomStatus::query()->sole()->getAttributes()))->toBe($before);
        expect(inTenant($t->b->property, fn () => StatusChange::query()->count()))->toBe(0);

        return $responses;
    }),
];
