<?php

use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\RoomAssignmentNight;
use Tests\Support\BookingForm;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

return [
    'reservations.room-assignment' => RouteCoverage::attempt(function (TwoTenants $t) {
        $foreign = inTenant($t->b->property, function () use ($t) {
            $details = BookingForm::details($t->b->owner);

            // Numbers repeat across properties; a distinct one proves lookups never cross them.
            return tap(app(CreateReservation::class)->handle($t->b->owner, [...$details, 'room_id' => Room::query()->sole()->id]), fn ($r) => $r->update(['reservation_number' => 'BRAVO-000001']));
        });
        $local = inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
        $otherRoom = inTenant($t->a->otherProperty, function () use ($t) {
            BookingForm::details($t->a->owner);

            return Room::query()->sole();
        });
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->patch(route('reservations.room-assignment', $foreign), ['room_id' => null, '_property' => $t->a->property->id])->assertNotFound();
        }
        $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner);
        foreach ([$foreign->room_id, $otherRoom->id] as $roomId) {
            $responses[] = $this->patchJson(route('reservations.room-assignment', $local), ['room_id' => $roomId, '_property' => $t->a->property->id])->assertUnprocessable()->assertJsonValidationErrors('room_id');
        }
        expect(inTenant($t->b->property, fn () => $foreign->fresh()->room_id))->toBe($foreign->room_id);
        expect(inTenant($t->b->property, fn () => RoomAssignmentNight::query()->count()))->toBe(2);
        expect(inTenant($t->a->property, fn () => $local->fresh()->room_id))->toBeNull();

        return $responses;
    }),
];
