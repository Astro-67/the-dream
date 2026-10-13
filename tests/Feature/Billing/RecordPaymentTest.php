<?php

use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Billing\Models\Receipt;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Validation\ValidationException;
use Tests\Support\BookingForm;
use Tests\Support\TenantFixture;

/** A folio on a new reservation in the property in context. */
function paymentFolio(User $owner, array $reservation = []): Folio
{
    $id = app(CreateReservation::class)->handle($owner, [...BookingForm::details($owner), ...$reservation])->id;

    return Folio::query()->where('reservation_id', $id)->sole();
}

test('a bank transfer posts to the folio, reduces its balance and gets a receipt', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $folio = paymentFolio($owner);

        $payment = app(RecordPayment::class)->handle($owner, $folio, ['method' => 'bank_transfer', 'amount' => 5000, 'currency' => 'USD', 'reference' => 'TT-889']);

        expect($payment->only(['type', 'method', 'reference', 'amount']))->toBe(['type' => 'payment', 'method' => 'bank_transfer', 'reference' => 'TT-889', 'amount' => -5000])
            ->and($folio->fresh()->balance()->amount)->toBe(-5000)
            ->and($payment->receipt?->number)->toBe('RCT-000001');
    });
});

test('mobile money and card payments post with their method and reduce the balance', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $folio = paymentFolio($owner);
        app(RecordPayment::class)->handle($owner, $folio, ['method' => 'mobile_money', 'amount' => 1000, 'currency' => 'USD', 'reference' => 'MPESA-1']);
        $card = app(RecordPayment::class)->handle($owner, $folio, ['method' => 'card', 'amount' => 2500, 'currency' => 'USD', 'reference' => 'POS-77', 'card_last_four' => '4242', 'card_brand' => 'visa']);

        expect($card->only(['method', 'card_last_four', 'card_brand']))->toBe(['method' => 'card', 'card_last_four' => '4242', 'card_brand' => 'visa'])
            ->and($folio->fresh()->balance()->amount)->toBe(-3500);
    });
});

test('a card payment refuses anything but the last four digits', function (string $digits) {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner, $digits) {
        $folio = paymentFolio($owner);

        expect(fn () => app(RecordPayment::class)->handle($owner, $folio, ['method' => 'card', 'amount' => 100, 'currency' => 'USD', 'card_last_four' => $digits]))
            ->toThrow(ValidationException::class);
        expect(Posting::query()->count())->toBe(0);
    });
})->with(['4111111111111111', '42', 'abcd']);

test('the channel-collected amount on the reservation posts once as a channel payment', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $folio = paymentFolio($owner, ['payment_collector' => 'channel', 'channel_collected_amount' => 24000]);

        $payment = app(RecordPayment::class)->handle($owner, $folio, ['method' => 'channel_collected', 'currency' => 'USD', 'reference' => 'BDC-1']);

        expect($payment->amount)->toBe(-24000)->and($payment->method)->toBe('channel_collected');
        expect(fn () => app(RecordPayment::class)->handle($owner, $folio, ['method' => 'channel_collected', 'currency' => 'USD']))
            ->toThrow(ValidationException::class);
    });
});

test('a property-collected reservation has no channel-collected payment', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        expect(fn () => app(RecordPayment::class)->handle($owner, paymentFolio($owner), ['method' => 'channel_collected', 'currency' => 'USD']))
            ->toThrow(ValidationException::class);
    });
});

test('a payment before arrival is a deposit, after check-in it is a payment', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $folio = paymentFolio($owner);
        $deposit = app(RecordPayment::class)->handle($owner, $folio, ['method' => 'bank_transfer', 'amount' => 100, 'currency' => 'USD']);
        Reservation::query()->whereKey($folio->reservation_id)->update(['status' => ReservationStatus::CheckedIn]);
        $payment = app(RecordPayment::class)->handle($owner, $folio, ['method' => 'bank_transfer', 'amount' => 100, 'currency' => 'USD']);

        expect($deposit->category)->toBe('deposit')->and($payment->category)->toBe('payment');
    });
});

test('receipt numbers run gap-free per property with the configured prefix', function () {
    $t = twoTenants();
    $t->a->property->update(['receipt_prefix' => 'SD/']);
    $folios = ['a' => inTenant($t->a->property, fn () => paymentFolio($t->a->owner)), 'b' => inTenant($t->b->property, fn () => paymentFolio($t->b->owner))];
    $pay = fn (TenantFixture $tenant, Folio $folio, int $amount = 100) => inTenant($tenant->property, fn () => app(RecordPayment::class)->handle($tenant->owner, $folio, ['method' => 'mobile_money', 'amount' => $amount, 'currency' => 'USD'])->receipt?->number);

    $first = $pay($t->a, $folios['a']);
    expect(fn () => $pay($t->a, $folios['a'], 0))->toThrow(ValidationException::class);
    $second = $pay($t->a, $folios['a']);

    expect([$first, $second, $pay($t->b, $folios['b'])])->toBe(['SD/000001', 'SD/000002', 'RCT-000001']);
});

test('repeating a payment with the same key posts once and issues one receipt', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $folio = paymentFolio($owner);
        $payment = ['method' => 'bank_transfer', 'amount' => 700, 'currency' => 'USD', 'idempotency_key' => 'pay-1'];

        $first = app(RecordPayment::class)->handle($owner, $folio, $payment);
        $again = app(RecordPayment::class)->handle($owner, $folio, $payment);

        expect($again->id)->toBe($first->id)->and(Posting::query()->count())->toBe(1)->and(Receipt::query()->count())->toBe(1);
    });
});

test('USD and TZS folios on one reservation balance independently', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $usd = paymentFolio($owner);
        $tzs = Folio::query()->create(['reservation_id' => $usd->reservation_id, 'currency' => 'TZS']);
        app(RecordPayment::class)->handle($owner, $usd, ['method' => 'card', 'amount' => 5000, 'currency' => 'USD']);
        app(RecordPayment::class)->handle($owner, $tzs, ['method' => 'mobile_money', 'amount' => 120000, 'currency' => 'TZS']);

        expect([$usd->fresh()->balance(), $tzs->fresh()->balance()])->toEqual([new Money(-5000, Currency::USD), new Money(-120000, Currency::TZS)]);
        expect(fn () => app(RecordPayment::class)->handle($owner, $tzs, ['method' => 'card', 'amount' => 1, 'currency' => 'USD']))->toThrow(ValidationException::class);
    });
});
