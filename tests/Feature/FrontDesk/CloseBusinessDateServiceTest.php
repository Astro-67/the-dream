<?php

use App\Domains\FrontDesk\Actions\CloseBusinessDate;
use App\Domains\Housekeeping\Actions\SetRoomOccupied;
use App\Domains\Housekeeping\Models\StayoverService;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\ReservationLifecycleEvent;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;
use Tests\Support\PropertyForm;

test('closing marks each occupied room for stayover service on the next date only when the setting is on', function (bool $enabled, int $expected) {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['business_date' => '2026-12-10', 'stayover_service_on_close' => $enabled]);
    inTenant($property, function () use ($owner, $property, $enabled, $expected) {
        $details = BookingForm::details($owner);
        app(CreateRoom::class)->handle($owner, [...InventoryForm::room($details['room_type_id']), 'number' => '2']);
        app(CreateRoom::class)->handle($owner, [...InventoryForm::room($details['room_type_id']), 'number' => '3']);
        $rooms = Room::query()->orderBy('number')->get();
        app(SetRoomOccupied::class)->handle($owner, $rooms[0], 'Check-in');
        app(SetRoomOccupied::class)->handle($owner, $rooms[1], 'Check-in');

        app(CloseBusinessDate::class)->handle(null, $property, '2026-12-10');

        expect(StayoverService::query()->whereDate('business_date', '2026-12-11')->pluck('room_id')->all())
            ->toEqualCanonicalizing($enabled ? [$rooms[0]->id, $rooms[1]->id] : [])
            ->and(StayoverService::query()->count())->toBe($expected);
    });
})->with([[true, 2], [false, 0]]);

test('a property manager can toggle stayover service on close and housekeeping cannot', function () {
    $t = twoTenants();
    $this->actingAs($t->a->member(OrganizationRole::Housekeeping))->put(route('properties.update', $t->a->property), PropertyForm::payload([
        '_property' => $t->a->property->id, 'stayover_service_on_close' => true,
    ]))->assertForbidden();
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager));
    $this->get(route('properties.edit', $t->a->property))->assertInertia(fn (Assert $page) => $page->where('property.stayover_service_on_close', false));
    $this->put(route('properties.update', $t->a->property), PropertyForm::payload([
        '_property' => $t->a->property->id, 'stayover_service_on_close' => true,
    ]))->assertRedirect()->assertSessionHasNoErrors();
    $this->get(route('properties.edit', $t->a->property))->assertInertia(fn (Assert $page) => $page->where('property.stayover_service_on_close', true));
    $this->put(route('properties.update', $t->a->property), PropertyForm::payload(['_property' => $t->a->property->id]))->assertSessionHasNoErrors();
    $this->get(route('properties.edit', $t->a->property))->assertInertia(fn (Assert $page) => $page->where('property.stayover_service_on_close', true));
});

// protects=night audit records only changed nightly prices once; fails_when=steady prices emit events or reruns duplicate them; no_claim=production authority delivery.
test('closing records a nightly price change only when the next night differs, once per reservation and date', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['business_date' => '2026-12-10', 'stayover_service_on_close' => true]);
    inTenant($property, function () use ($owner, $property) {
        $details = BookingForm::details($owner);
        app(CreateRoom::class)->handle($owner, [...InventoryForm::room($details['room_type_id']), 'number' => '2']);
        $rooms = Room::query()->orderBy('number')->get();
        [$changed, $steady] = [
            app(CreateReservation::class)->handle($owner, [...$details, 'room_id' => $rooms[0]->id]),
            app(CreateReservation::class)->handle($owner, [...$details, 'room_id' => $rooms[1]->id]),
        ];
        foreach ([$changed, $steady] as $reservation) {
            $reservation->update(['status' => ReservationStatus::CheckedIn]);
        }
        $changed->priceLines()->whereDate('date', '2026-12-11')->update(['amount' => 9000]);
        app(SetRoomOccupied::class)->handle($owner, Room::query()->firstOrFail(), 'Check-in');

        app(CloseBusinessDate::class)->handle(null, $property, '2026-12-10');
        app(CloseBusinessDate::class)->handle(null, $property, '2026-12-10');

        $event = ReservationLifecycleEvent::query()->where('type', 'nightly_price_changed')->sole();
        expect($event->reservation_id)->toBe($changed->id)
            ->and($event->business_date->toDateString())->toBe('2026-12-10')
            ->and($event->actor_id)->toBeNull()
            ->and($event->snapshot)->toMatchArray(['date' => '2026-12-11', 'previous_amount' => 11000, 'amount' => 9000, 'currency' => 'USD'])
            ->and(StayoverService::query()->count())->toBe(1);
    });
});
