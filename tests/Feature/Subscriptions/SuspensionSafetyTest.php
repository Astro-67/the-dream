<?php

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Billing\Models\Receipt;
use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Subscriptions\Enums\SubscriptionStatus;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Tenancy\Enums\PropertyStatus;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\Http;
use Tests\Support\BookingForm;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 12, 10)->startOfDay());
    config(['queue.default' => 'database']);
    Http::preventStrayRequests();
});

test('suspension preserves an existing stay from check-in through payment checkout filing and export', function (VfmsMode $mode) {
    [$owner, $organization, $property] = organizationWithOwner();
    $property->update(['business_date' => '2026-12-10']);
    [$reservation, $folio] = inTenant($property, function () use ($owner, $mode) {
        $details = BookingForm::details($owner);
        $reservation = app(CreateReservation::class)->handle($owner, [...$details, 'room_id' => Room::query()->sole()->id]);
        $guest = app(CreateGuest::class)->handle($owner, [
            'name' => 'Existing guest',
            'documents' => [['type' => 'passport', 'number' => 'P7654321', 'nationality' => 'DE']],
        ]);
        ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        VfmsCredential::query()->create([
            'mode' => $mode, 'integration_id' => 'INT-198', 'registration_id' => 'REG-198', 'token' => 'TOKEN-198',
        ]);
        Subscription::current()->update(['status' => SubscriptionStatus::Suspended]);

        return [$reservation, Folio::query()->sole()];
    });
    $this->actingAs($owner)->withSession(['tenancy.organization_id' => $organization->id, 'tenancy.property_id' => $property->id]);

    $this->post(route('reservations.check-in', $reservation), ['_property' => $property->id])
        ->assertRedirect(route('reservations.show', $reservation))->assertSessionHasNoErrors();
    expect($reservation->fresh()->status)->toBe(ReservationStatus::CheckedIn);
    $this->post(route('folios.payments.store', $folio), [
        '_property' => $property->id, 'method' => 'bank_transfer', 'amount' => 1000, 'idempotency_key' => 'suspension-safety-payment',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $property->update(['business_date' => '2026-12-12']);
    $this->post(route('reservations.check-out', $reservation), [
        '_property' => $property->id, 'override_reason' => 'Balance settled externally',
    ])->assertRedirect()->assertSessionHasNoErrors();

    inTenant($property, function () use ($reservation, $folio, $mode) {
        expect($reservation->fresh()->status)->toBe(ReservationStatus::CheckedOut)
            ->and(Posting::query()->where('folio_id', $folio->id)->where('type', 'payment')->sole()->amount)->toBe(-1000)
            ->and(Subscription::current()->status)->toBe(SubscriptionStatus::Suspended);
        $messages = OutboxMessage::query()->where('integration', 'vfms')->where('subject_id', $reservation->id)->orderBy('sequence')->get();
        expect($messages->pluck('event')->all())->toBe(['accommodation', 'checkout'])
            ->and($messages->pluck('status')->all())->toBe(['pending', 'pending'])
            ->and($messages->pluck('payload.mode')->all())->toBe([$mode->value, $mode->value]);
    });
    $receipt = inTenant($property, fn () => Receipt::query()->sole());
    $this->get(route('receipts.show', $receipt))->assertOk();
    $this->get(route('reports.filings.csv', ['from' => '2026-12-10', 'to' => '2026-12-12']))
        ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertSee($reservation->reservation_number);
    expect($property->fresh()->status)->toBe(PropertyStatus::Live);
})->with(VfmsMode::cases());
