<?php

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Billing\Models\Receipt;
use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Actions\DeactivateRoom;
use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Subscriptions\Enums\SubscriptionStatus;
use App\Domains\Subscriptions\Models\Plan;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\PlanUsage;
use App\Domains\Tenancy\Actions\ArchiveProperty;
use App\Domains\Tenancy\Actions\CreateProperty;
use App\Domains\Tenancy\Actions\RestoreProperty;
use App\Domains\Tenancy\Data\PropertyDetails;
use App\Domains\Tenancy\Models\Property;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;

test('property additions stop at the limit and refusals are audited without counting another organization', function () {
    [$owner, $organization, $property] = organizationWithOwner();
    organizationWithOwner();
    Plan::default()->update(['property_limit' => 2]);
    $payload = [...organizationSetupPayload(), 'name' => 'Second property', '_property' => $property->id];
    $this->actingAs($owner)->post(route('properties.store'), $payload)->assertSessionHasNoErrors();
    $this->postJson(route('properties.store'), [...$payload, 'name' => 'Third property'])
        ->assertUnprocessable()->assertJsonValidationErrors('property')
        ->assertJsonPath('errors.property.0', 'Your plan allows 2 active properties. Archive a property or change your plan before adding another.');

    expect(inTenant($organization, fn () => Property::query()->count()))->toBe(2)
        ->and(Activity::query()->where('description', 'Property creation refused')->sole()->getExtraProperty('result'))->toBe('refused');
});

test('room capacity and displayed usage cover every real property even in property context', function () {
    [$owner, $organization, $property] = organizationWithOwner();
    $second = inTenant($organization, fn () => app(CreateProperty::class)->handle($owner, PropertyDetails::fromArray(organizationSetupPayload(), 'property_name')));
    $firstType = inTenant($property, fn () => app(CreateRoomType::class)->handle($owner, InventoryForm::roomType()));
    $secondType = inTenant($second, fn () => app(CreateRoomType::class)->handle($owner, InventoryForm::roomType()));
    Plan::default()->update(['room_limit' => 2]);
    inTenant($property, fn () => app(CreateRoom::class)->handle($owner, InventoryForm::room($firstType->id)));
    $room = inTenant($second, fn () => app(CreateRoom::class)->handle($owner, InventoryForm::room($secondType->id)));
    $this->actingAs($owner)->withSession(['tenancy.organization_id' => $organization->id, 'tenancy.property_id' => $property->id]);
    $this->postJson(route('rooms.store'), InventoryForm::room($firstType->id, ['number' => '102', '_property' => $property->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('room')
        ->assertJsonPath('errors.room.0', 'Your plan allows 2 active rooms. Deactivate rooms or change your plan before adding more.');
    $this->get(route('subscription.show'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('usage', ['properties' => 2, 'rooms' => 2]));
    expect(inTenant($organization, fn () => Room::query()->count()))->toBe(2)
        ->and(Activity::query()->where('description', 'Room creation refused')->sole()->getExtraProperty('result'))->toBe('refused');

    inTenant($second, fn () => app(DeactivateRoom::class)->handle($owner, $room));
    $this->post(route('rooms.store'), InventoryForm::room($firstType->id, ['number' => '102', '_property' => $property->id]))->assertSessionHasNoErrors();
    expect(inTenant($property, fn () => PlanUsage::current()))->toBe(['properties' => 2, 'rooms' => 2]);
});

test('demo creation and sample sales remain usable without consuming suspended or cancelled plan capacity', function (SubscriptionStatus $status) {
    [$owner, $organization, $property] = organizationWithOwner();
    Plan::default()->update(['property_limit' => 1, 'room_limit' => 1]);
    inTenant($organization, fn () => Subscription::current()->update(['status' => $status]));

    $this->actingAs($owner)->post(route('properties.demo.store'), ['_property' => $property->id])->assertSessionHasNoErrors();
    $demo = inTenant($organization, fn () => Property::query()->where('demo', true)->sole());
    expect(inTenant($demo, fn () => Room::query()->count()))->toBe(5)
        ->and(inTenant($demo, fn () => Reservation::query()->count()))->toBeGreaterThan(0)
        ->and(inTenant($demo, fn () => PlanUsage::current()))->toBe(['properties' => 1, 'rooms' => 0]);

    inTenant($organization, fn () => Subscription::current()->update(['status' => SubscriptionStatus::Active]));
    $type = inTenant($property, fn () => app(CreateRoomType::class)->handle($owner, InventoryForm::roomType()));
    inTenant($property, fn () => app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id)));
    expect(inTenant($organization, fn () => PlanUsage::current()))->toBe(['properties' => 1, 'rooms' => 1]);
})->with([SubscriptionStatus::Suspended, SubscriptionStatus::Cancelled]);

test('suspended and cancelled subscriptions block each new sales action but leave inventory untouched', function (SubscriptionStatus $status) {
    [$owner, $organization, $property] = organizationWithOwner();
    $details = inTenant($property, fn () => BookingForm::details($owner));
    $inventory = inTenant($property, fn () => InventoryRecord::query()->sum('sold_count'));
    inTenant($organization, fn () => Subscription::current()->update(['status' => $status]));
    $this->actingAs($owner)->withSession(['tenancy.organization_id' => $organization->id, 'tenancy.property_id' => $property->id]);

    $this->postJson(route('properties.store'), [...organizationSetupPayload(), 'name' => 'Blocked property', '_property' => $property->id])
        ->assertUnprocessable()->assertJsonValidationErrors('subscription');
    $this->postJson(route('rooms.store'), InventoryForm::room($details['room_type_id'], ['number' => '102', '_property' => $property->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('subscription');
    $this->postJson(route('reservations.store'), [...$details, '_property' => $property->id])
        ->assertUnprocessable()->assertJsonValidationErrors('subscription');
    expect(fn () => inTenant($property, fn () => app(CreateReservation::class)->handle($owner, $details)))
        ->toThrow(ValidationException::class);

    expect(inTenant($organization, fn () => Property::query()->count()))->toBe(1)
        ->and(inTenant($property, fn () => Room::query()->count()))->toBe(1)
        ->and(inTenant($property, fn () => Reservation::query()->count()))->toBe(0)
        ->and(inTenant($property, fn () => Folio::query()->count()))->toBe(0)
        ->and(inTenant($property, fn () => InventoryRecord::query()->sum('sold_count')))->toEqual($inventory);
})->with([SubscriptionStatus::Suspended, SubscriptionStatus::Cancelled]);

test('trial active and past due subscriptions still permit additions and reservations', function (SubscriptionStatus $status) {
    [$owner, $organization, $property] = organizationWithOwner();
    inTenant($organization, fn () => Subscription::current()->update(['status' => $status]));
    inTenant($organization, fn () => app(CreateProperty::class)->handle($owner, PropertyDetails::fromArray(organizationSetupPayload(), 'property_name')));
    $reservation = inTenant($property, fn () => app(CreateReservation::class)->handle($owner, BookingForm::details($owner)));

    expect($reservation->status)->toBe(ReservationStatus::Confirmed)
        ->and(inTenant($property, fn () => PlanUsage::current()))->toBe(['properties' => 2, 'rooms' => 1]);
})->with([SubscriptionStatus::Trial, SubscriptionStatus::Active, SubscriptionStatus::PastDue]);

test('restoring a property checks both its property slot and all of its active rooms', function (int $propertyLimit, int $roomLimit, ?string $error) {
    [$owner, $organization, $property] = organizationWithOwner();
    $archived = inTenant($organization, fn () => app(CreateProperty::class)->handle($owner, PropertyDetails::fromArray(organizationSetupPayload(), 'property_name')));
    inTenant($archived, function () use ($owner) {
        $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType());
        app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id));
        app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id, ['number' => '102']));
    });
    inTenant($organization, fn () => app(ArchiveProperty::class)->handle($owner, $archived));
    Plan::default()->update(['property_limit' => $propertyLimit, 'room_limit' => $roomLimit]);
    $this->actingAs($owner)->withSession(['tenancy.organization_id' => $organization->id, 'tenancy.property_id' => $property->id]);
    $response = $this->deleteJson(route('properties.restore', $archived), ['_property' => $property->id]);
    if ($error !== null) {
        $response->assertUnprocessable()->assertJsonValidationErrors($error);
        expect($archived->fresh()->archived_at)->not->toBeNull();
    } else {
        $response->assertRedirect();
        expect($archived->fresh()->archived_at)->toBeNull();
        inTenant($organization, fn () => app(RestoreProperty::class)->handle($owner, $archived));
        expect(inTenant($organization, fn () => PlanUsage::current()))->toBe(['properties' => 2, 'rooms' => 2]);
    }
    expect(inTenant($archived, fn () => Room::query()->count()))->toBe(2);
})->with([[1, 10, 'property'], [2, 1, 'room'], [2, 2, null]]);

test('restoration cannot bypass a suspended or cancelled subscription', function (SubscriptionStatus $status) {
    [$owner, $organization, $property] = organizationWithOwner();
    $archived = inTenant($organization, fn () => app(CreateProperty::class)->handle($owner, PropertyDetails::fromArray(organizationSetupPayload(), 'property_name')));
    inTenant($organization, fn () => app(ArchiveProperty::class)->handle($owner, $archived));
    inTenant($organization, fn () => Subscription::current()->update(['status' => $status]));

    $this->actingAs($owner)->deleteJson(route('properties.restore', $archived), ['_property' => $property->id])
        ->assertUnprocessable()->assertJsonValidationErrors('subscription');
    expect($archived->fresh()->archived_at)->not->toBeNull();
})->with([SubscriptionStatus::Suspended, SubscriptionStatus::Cancelled]);

test('a downgrade below usage deletes nothing and only blocks further additions', function () {
    [$owner, $organization, $property] = organizationWithOwner();
    inTenant($organization, fn () => app(CreateProperty::class)->handle($owner, PropertyDetails::fromArray(organizationSetupPayload(), 'property_name')));
    $details = inTenant($property, fn () => BookingForm::details($owner));
    inTenant($property, fn () => app(CreateRoom::class)->handle($owner, InventoryForm::room($details['room_type_id'], ['number' => '102'])));
    $plan = Plan::default();
    $this->actingAs(platformStaff())->put(route('platform.plans.update', $plan), [
        ...$plan->only(Plan::FIELDS), 'billing_period' => $plan->billing_period->value, 'currency' => $plan->currency->value,
        'property_limit' => 1, 'room_limit' => 1,
    ])->assertSessionHasNoErrors();
    $this->actingAs($owner)->withSession(['tenancy.organization_id' => $organization->id, 'tenancy.property_id' => $property->id]);
    $this->postJson(route('properties.store'), [...organizationSetupPayload(), 'name' => 'Blocked', '_property' => $property->id])
        ->assertUnprocessable()->assertJsonValidationErrors('property');
    $this->postJson(route('rooms.store'), InventoryForm::room($details['room_type_id'], ['number' => '103', '_property' => $property->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('room');
    $this->post(route('reservations.store'), [...$details, '_property' => $property->id])->assertSessionHasNoErrors();

    expect(inTenant($property, fn () => PlanUsage::current()))->toBe(['properties' => 2, 'rooms' => 2])
        ->and(inTenant($organization, fn () => Property::query()->active()->count()))->toBe(2)
        ->and(inTenant($property, fn () => Room::query()->active()->count()))->toBe(2)
        ->and(inTenant($property, fn () => Reservation::query()->count()))->toBe(1);
});

test('existing guest check-out payments and data access remain available during suspension or cancellation', function (SubscriptionStatus $status) {
    [$owner, $organization, $property] = organizationWithOwner();
    $property->update(['business_date' => '2026-12-10']);
    [$reservation, $folio] = inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        $reservation = app(CreateReservation::class)->handle($owner, [...$details, 'room_id' => Room::query()->sole()->id]);
        $guest = app(CreateGuest::class)->handle($owner, ['name' => 'Existing guest', 'documents' => [['type' => 'passport', 'number' => 'P7654321', 'nationality' => 'DE']]]);
        ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        $reservation = app(CheckIn::class)->handle($owner, $reservation);

        return [$reservation, Folio::query()->sole()];
    });
    $property->update(['business_date' => '2026-12-12']);
    inTenant($organization, fn () => Subscription::current()->update(['status' => $status]));
    $this->actingAs($owner)->withSession(['tenancy.organization_id' => $organization->id, 'tenancy.property_id' => $property->id]);

    $this->post(route('folios.payments.store', $folio), [
        '_property' => $property->id, 'method' => 'bank_transfer', 'amount' => 1000, 'idempotency_key' => 'suspended-payment',
    ])->assertSessionHasNoErrors();
    $this->post(route('reservations.check-out', $reservation), ['_property' => $property->id, 'override_reason' => 'Balance settled externally'])
        ->assertSessionHasNoErrors();
    $receipt = inTenant($property, fn () => Receipt::query()->sole());
    $this->get(route('receipts.show', $receipt))->assertOk();
    $this->get(route('reservations.show', $reservation))->assertOk();
    $this->get(route('reservations.index'))->assertOk();
    $this->get(route('properties.index'))->assertOk();
    expect($reservation->fresh()->status)->toBe(ReservationStatus::CheckedOut)
        ->and(inTenant($property, fn () => Posting::query()->where('folio_id', $folio->id)->where('type', 'payment')->count()))->toBe(1);
})->with([SubscriptionStatus::Suspended, SubscriptionStatus::Cancelled]);
