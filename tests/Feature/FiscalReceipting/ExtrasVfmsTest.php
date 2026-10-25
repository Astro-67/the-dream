<?php

use App\Domains\Billing\Actions\OpenFolio;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\ProposeFee;
use App\Domains\Billing\Actions\ResolveFee;
use App\Domains\Billing\Models\Folio;
use App\Domains\FiscalReceipting\Adapters\SandboxVfms;
use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\FiscalReceipting\VfmsPayloads;
use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\PropertyStatus;
use App\Support\Outbox\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\TenantFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam'));
    Http::preventStrayRequests();
});

function extraVfmsStay(TenantFixture $tenant, bool $checkIn = true): Reservation
{
    $details = BookingForm::details($tenant->owner);
    RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
    $reservation = app(CreateReservation::class)->handle($tenant->owner, [
        ...$details, 'room_id' => Room::query()->sole()->id, 'meal_plan' => 'bed-and-breakfast',
    ]);
    foreach ([['Anna Berg', 'P1234567'], ['Ola Berg', 'P7654321']] as [$name, $number]) {
        $guest = app(CreateGuest::class)->handle($tenant->owner, [
            'name' => $name, 'documents' => [['type' => 'passport', 'number' => $number, 'nationality' => 'DE']],
        ]);
        ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);
    }

    return $checkIn ? app(CheckIn::class)->handle($tenant->owner, $reservation) : $reservation;
}

// protects=an in-house extra files guide §2.11 services and keeps its fiscal receipt (#163); fails_when=the payload differs or the receipt is not saved on the charge; no_claim=production VFMS acceptance.
test('an in-house extra files one Sandbox services message with its receipt', function () {
    $tenant = twoTenants()->a;
    [$reservation, $posting] = inTenant($tenant->property, function () use ($tenant) {
        $reservation = extraVfmsStay($tenant);

        $posting = app(PostExtraCharge::class)->handle($tenant->owner, Folio::query()->sole(), [
            'category' => 'bar', 'description' => 'WATER', 'quantity' => 2, 'unit_price' => 1000, 'idempotency_key' => 'water-1',
        ]);

        $event = $reservation->lifecycleEvents()->where('type', 'extra_posted')->sole();
        $message = OutboxMessage::query()->where('integration', 'vfms')->where('event', 'services')->sole();
        expect($message->status)->toBe('delivered')
            ->and($message->payload['path'])->toBe('service/')
            ->and($message->payload['request_type'])->toBe('ACCOMMODATION_ADD_SERVICE')
            ->and($message->payload['body'])->toEqual([
                'documentNumber' => 'P1234567',
                'service' => [['itemName' => 'WATER', 'price' => 10, 'quantity' => 2, 'discount' => 0]],
                'referenceNumber' => $reservation->reservation_number.'-'.$event->sequence,
                'type' => 'CS',
                'currency' => 'USD',
            ])
            ->and(app(VfmsPayloads::class)->services($event, $reservation, $tenant->property)['service'][0]['price'])->toBeFloat()
            ->and($posting->fresh()->vfms_receipt_number)->toStartWith('SBX-')
            ->and($posting->fresh()->vfms_receipt_number)->toBe($message->getAttribute('provider_references')['receiptNumber']);

        return [$reservation, $posting->fresh()];
    });
    $this->actingAs($tenant->owner)
        ->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id])
        ->get(route('reservations.show', $reservation))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('folios.data.0.postings.data.0.vfms_receipt_number', $posting->vfms_receipt_number));
    Http::assertNothingSent();
});

// protects=retrying one submission does not double-file extras (#163); fails_when=the same key creates another event or services message; no_claim=unknown-outcome reconciliation (#165).
test('a repeated extra submission records one event and one services message', function () {
    $tenant = twoTenants()->a;
    inTenant($tenant->property, function () use ($tenant) {
        $reservation = extraVfmsStay($tenant);
        $folio = Folio::query()->sole();
        $details = ['category' => 'laundry', 'quantity' => 2, 'unit_price' => 500, 'idempotency_key' => 'laundry-1'];
        $first = app(PostExtraCharge::class)->handle($tenant->owner, $folio, $details);
        $again = app(PostExtraCharge::class)->handle($tenant->owner, $folio, $details);

        expect($again->id)->toBe($first->id)
            ->and($again->vfms_receipt_number)->toStartWith('SBX-')
            ->and($reservation->lifecycleEvents()->where('type', 'extra_posted')->count())->toBe(1)
            ->and(OutboxMessage::query()->where('integration', 'vfms')->where('event', 'services')->count())->toBe(1);
    });
});

// protects=only in-house stays' extras are filed (#163); fails_when=an extra outside an in-house stay files services; no_claim=validity of reservation status transitions.
test('extras outside an in-house stay are not filed', function (ReservationStatus $status) {
    $tenant = twoTenants()->a;
    inTenant($tenant->property, function () use ($tenant, $status) {
        $reservation = extraVfmsStay($tenant, false);
        $reservation->update(['status' => $status]);
        $posting = app(PostExtraCharge::class)->handle($tenant->owner, Folio::query()->sole(), ['quantity' => 1, 'unit_price' => 500]);

        expect($reservation->lifecycleEvents()->where('type', 'extra_posted')->count())->toBe(1)
            ->and(OutboxMessage::query()->where('integration', 'vfms')->count())->toBe(0)
            ->and($posting->fresh()->vfms_receipt_number)->toBeNull();
    });
})->with([ReservationStatus::Confirmed, ReservationStatus::Cancelled, ReservationStatus::NoShow, ReservationStatus::CheckedOut]);

// protects=fees remain fiscal exceptions rather than guest services (#163); fails_when=posting a cancellation or no-show fee files services; no_claim=fee policy calculations.
test('cancellation and no-show fees remain fiscal exceptions', function (string $kind) {
    $tenant = twoTenants()->a;
    inTenant($tenant->property, function () use ($tenant, $kind) {
        $reservation = extraVfmsStay($tenant, false);
        RatePlan::query()->whereKey($reservation->getAttribute('rate_plan_id'))->update([
            'cancellation_charge' => 'first_night', 'cancellation_free_days' => null, 'no_show_charge' => 'first_night',
        ]);
        $proposal = app(ProposeFee::class)->handle($tenant->owner, $reservation, $kind);
        $resolved = app(ResolveFee::class)->handle($tenant->owner, $proposal, 'post', 'Apply agreed policy');

        expect($resolved->fiscal_exception_candidate)->toBeTrue()
            ->and(OutboxMessage::query()->where('integration', 'vfms')->count())->toBe(0);
    });
})->with(['cancellation', 'no_show']);

// protects=jurisdiction and test-data gates also cover extras (spec #156); fails_when=mainland or setup-property extras produce a VFMS message; no_claim=mainland TRA filing.
test('mainland and test-property extras are not filed', function (string $scenario) {
    $tenant = twoTenants()->a;
    $property = $scenario === 'mainland' ? $tenant->otherProperty : $tenant->property;
    if ($scenario === 'test') {
        $property->forceFill(['status' => PropertyStatus::Setup])->save();
    }
    inTenant($property, function () use ($tenant, $scenario) {
        $reservation = extraVfmsStay($tenant, $scenario !== 'test');
        if ($scenario === 'test') {
            $reservation->update(['status' => ReservationStatus::CheckedIn]);
        }
        $posting = app(PostExtraCharge::class)->handle($tenant->owner, Folio::query()->sole(), ['quantity' => 1, 'unit_price' => 500]);
        expect(OutboxMessage::query()->where('integration', 'vfms')->count())->toBe(0)
            ->and($posting->fresh()->vfms_receipt_number)->toBeNull();
    });
    Http::assertNothingSent();
})->with(['mainland', 'test']);

// protects=receipt storage follows accepted delivery only (#163); fails_when=a rejected or unknown service saves a receipt, or acceptance loses it; no_claim=production availability.
test('live services save a receipt only for an accepted outcome', function (int $status, string $outcome) {
    $tenant = twoTenants()->a;
    inTenant($tenant->property, function () use ($tenant, $status, $outcome) {
        extraVfmsStay($tenant);
        VfmsCredential::query()->create(['integration_id' => 'INT-1001', 'registration_id' => 'REG-2002', 'token' => 'secret', 'mode' => VfmsMode::Live]);
        Http::fake(['gateway.zanrevenue.org/api/service/' => Http::response(['receiptNumber' => 'FISCAL-163'], $status)]);
        $posting = app(PostExtraCharge::class)->handle($tenant->owner, Folio::query()->sole(), ['quantity' => 1, 'unit_price' => 500]);

        expect(OutboxMessage::query()->where('event', 'services')->sole()->status)->toBe($outcome)
            ->and($posting->fresh()->vfms_receipt_number)->toBe($outcome === 'delivered' ? 'FISCAL-163' : null);
    });
    Http::assertSentCount(1);
})->with([[200, 'delivered'], [201, 'delivered'], [400, 'rejected'], [503, 'unknown']]);

// protects=all supported extras use the charge currency and guide units (#163, §2.11); fails_when=a category is skipped or TZS/EURO is converted incorrectly; no_claim=currency conversion.
test('every extra category uses the folio currency and major-unit price', function (string $currency, string $vfmsCurrency, int $price) {
    $tenant = twoTenants()->a;
    inTenant($tenant->property, function () use ($tenant, $currency, $vfmsCurrency, $price) {
        $reservation = extraVfmsStay($tenant);
        $folio = app(OpenFolio::class)->handle($tenant->owner, $reservation, $currency);
        foreach (PostExtraCharge::CATEGORIES as $category) {
            $posting = app(PostExtraCharge::class)->handle($tenant->owner, $folio, ['category' => $category, 'quantity' => 1, 'unit_price' => 1000]);
            expect($posting->fresh()->vfms_receipt_number)->toStartWith('SBX-');
        }
        $messages = OutboxMessage::query()->where('event', 'services')->orderBy('sequence')->get();
        expect($messages)->toHaveCount(count(PostExtraCharge::CATEGORIES));
        foreach ($messages as $index => $message) {
            expect($message->status)->toBe('delivered')
                ->and($message->payload['body']['currency'])->toBe($vfmsCurrency)
                ->and($message->payload['body']['service'][0])->toEqual([
                    'itemName' => PostExtraCharge::CATEGORIES[$index], 'price' => $price, 'quantity' => 1, 'discount' => 0,
                ]);
        }
    });
})->with([['USD', 'USD', 10], ['EUR', 'EURO', 10], ['TZS', 'TZS', 1000]]);

// protects=receipt metadata never weakens append-only ledger fields (#163, FIN-02); fails_when=attaching a receipt saves dirty money fields or replaces an existing receipt; no_claim=database administrator bypasses.
test('recording a fiscal receipt changes only receipt metadata and cannot replace it', function () {
    $tenant = twoTenants()->a;
    inTenant($tenant->property, function () use ($tenant) {
        extraVfmsStay($tenant, false);
        $posting = app(PostExtraCharge::class)->handle($tenant->owner, Folio::query()->sole(), ['quantity' => 1, 'unit_price' => 500]);
        $posting->amount = 1;
        $posting->recordFiscalReceipt('FISCAL-163');
        $posting->recordFiscalReceipt('FISCAL-163');

        expect($posting->fresh()->amount)->toBe(500)
            ->and($posting->fresh()->vfms_receipt_number)->toBe('FISCAL-163');
        expect(fn () => $posting->recordFiscalReceipt('REPLACEMENT'))->toThrow(LogicException::class);
        expect(fn () => $posting->save())->toThrow(LogicException::class, 'append-only');
    });
});

// protects=Sandbox rejects malformed services instead of certifying invalid guide §2.11 requests; fails_when=missing fields or wrong types are accepted; no_claim=undocumented VFMS validation.
test('Sandbox validates services and their item fields', function (array $override) {
    $tenant = twoTenants()->a;
    $result = app(SandboxVfms::class)->send([
        'request_type' => 'ACCOMMODATION_ADD_SERVICE', 'path' => 'service/',
        'body' => [
            'documentNumber' => 'P1234567',
            'service' => [['itemName' => 'Water', 'price' => 10.0, 'quantity' => 2, 'discount' => 0.0]],
            'referenceNumber' => 'R-163-3', 'type' => 'CS', 'currency' => 'USD',
            ...$override,
        ],
    ], VfmsCredential::for($tenant->property));

    expect($result->outcome)->toBe('rejected');
})->with([
    'missing document' => [['documentNumber' => null]],
    'wrong services type' => [['type' => 'WS']],
    'empty services' => [['service' => []]],
    'missing name' => [['service' => [['price' => 10.0, 'quantity' => 2, 'discount' => 0.0]]]],
    'missing discount' => [['service' => [['itemName' => 'Water', 'price' => 10.0, 'quantity' => 2]]]],
    'string price' => [['service' => [['itemName' => 'Water', 'price' => '10', 'quantity' => 2, 'discount' => 0.0]]]],
    'fractional quantity' => [['service' => [['itemName' => 'Water', 'price' => 10.0, 'quantity' => 1.5, 'discount' => 0.0]]]],
    'negative discount' => [['service' => [['itemName' => 'Water', 'price' => 10.0, 'quantity' => 2, 'discount' => -1.0]]]],
    'non-array services' => [['service' => 'Water']],
    'non-object item' => [['service' => ['Water']]],
    'zero quantity' => [['service' => [['itemName' => 'Water', 'price' => 10.0, 'quantity' => 0, 'discount' => 0.0]]]],
    'negative price' => [['service' => [['itemName' => 'Water', 'price' => -1.0, 'quantity' => 2, 'discount' => 0.0]]]],
]);
