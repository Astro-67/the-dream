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
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Subscriptions\Enums\SaasInvoiceStatus;
use App\Domains\Subscriptions\Enums\SubscriptionStatus;
use App\Domains\Subscriptions\Models\SaasInvoice;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Notifications\SaasInvoiceReminder;
use App\Domains\Tenancy\Enums\PropertyStatus;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
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

test('an overdue renewal suspends only new sales and full payment restores them without taking the property out of live', function () {
    Notification::fake();
    withBillingDetails();
    $this->travelTo(now()->setDate(2026, 11, 23));
    [$owner, $organization, $property] = organizationWithOwner();
    $details = inTenant($property, fn () => BookingForm::details($owner));
    inTenant($organization, fn () => Subscription::current()->update([
        'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addDays(3),
    ]));

    $this->artisan('subscriptions:advance')->assertSuccessful();
    $invoice = inTenant($organization, fn () => SaasInvoice::query()->sole());
    expect($invoice->due_at->toDateString())->toBe('2026-11-26')
        ->and(inTenant($organization, fn () => Subscription::current()->status))->toBe(SubscriptionStatus::Active);
    Notification::assertSentTo($owner, SaasInvoiceReminder::class, fn ($reminder) => ! $reminder->overdue);

    $this->travel(3)->days();
    $this->artisan('subscriptions:advance')->assertSuccessful();
    expect(inTenant($organization, fn () => Subscription::current()->status))->toBe(SubscriptionStatus::PastDue);
    Notification::assertSentTo($owner, SaasInvoiceReminder::class, fn ($reminder) => $reminder->overdue);

    $this->travel(14)->days();
    $this->artisan('subscriptions:advance')->assertSuccessful();
    expect(inTenant($organization, fn () => Subscription::current()->status))->toBe(SubscriptionStatus::Suspended)
        ->and($property->fresh()->status)->toBe(PropertyStatus::Live);
    $this->actingAs($owner)->withSession(['tenancy.organization_id' => $organization->id, 'tenancy.property_id' => $property->id]);
    $this->postJson(route('reservations.store'), [...$details, '_property' => $property->id])
        ->assertUnprocessable()->assertJsonValidationErrors('subscription');

    $admin = platformStaff();
    $payment = ['method' => 'bank_transfer', 'reference' => 'RENEWAL-198', 'received_on' => now()->toDateString()];
    $this->actingAs($admin)->post(route('platform.invoices.payments.store', $invoice->id), [...$payment, 'amount' => 1000])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($invoice->fresh()->status)->toBe(SaasInvoiceStatus::Open)
        ->and(inTenant($organization, fn () => Subscription::current()->status))->toBe(SubscriptionStatus::Suspended);

    $this->post(route('platform.invoices.payments.store', $invoice->id), [...$payment, 'reference' => 'RENEWAL-198-BALANCE', 'amount' => $invoice->amount - 1000])
        ->assertRedirect()->assertSessionHasNoErrors();
    $subscription = inTenant($organization, fn () => Subscription::current());
    expect($invoice->fresh()->status)->toBe(SaasInvoiceStatus::Paid)
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->current_period_ends_at->toDateString())->toBe('2026-12-26')
        ->and($property->fresh()->status)->toBe(PropertyStatus::Live);

    $this->actingAs($owner)->post(route('reservations.store'), [...$details, '_property' => $property->id])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(inTenant($property, fn () => Reservation::query()->sole()->status))->toBe(ReservationStatus::Confirmed);
});
