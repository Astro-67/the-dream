<?php

use App\Domains\Billing\Actions\OpenFolio;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\FiscalReceipting\Models\IssuedDocument;
use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\FrontDesk\Actions\CloseBusinessDate;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\AssignRoom;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Support\Outbox\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;
use Tests\Support\TenantFixture;
use Tests\Support\VfmsHttp;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam'));
    VfmsHttp::fake();
});

/** One room, Anna Berg (P1234567) as lead guest; checked in unless $checkIn is false. */
function generalReceiptStay(TenantFixture $tenant, array $details = [], bool $checkIn = true): Reservation
{
    return inTenant($tenant->property, function () use ($tenant, $details, $checkIn) {
        $form = BookingForm::details($tenant->owner);
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        $reservation = app(CreateReservation::class)->handle($tenant->owner, [
            ...$form, 'adults' => 1, 'children' => 0, 'nightly_rate' => 11000,
            'room_id' => Room::query()->sole()->id, 'meal_plan' => 'bed-and-breakfast', ...$details,
        ]);
        $guest = app(CreateGuest::class)->handle($tenant->owner, ['name' => 'Anna Berg', 'documents' => [['type' => 'passport', 'number' => 'P1234567', 'nationality' => 'DE']]]);
        ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);

        return $checkIn ? app(CheckIn::class)->handle($tenant->owner, $reservation) : $reservation;
    });
}

/** Posts an extra through the real charge route as the owner. */
function postGeneralReceiptExtra($test, TenantFixture $tenant, Folio $folio, array $charge): Posting
{
    $test->actingAs($tenant->owner)->post(route('folios.charges.store', $folio), [
        '_property' => $tenant->property->id, 'category' => 'restaurant', 'description' => 'LUNCH', 'quantity' => 1, 'unit_price' => 1000,
        'idempotency_key' => (string) Str::ulid(), ...$charge,
    ])->assertSessionHasNoErrors();

    return inTenant($tenant->property, fn () => Posting::query()->where('folio_id', $folio->id)->where('type', 'charge')->where('category', '!=', 'room')->latest('posted_at')->orderByDesc('id')->firstOrFail());
}

/** Replaces the fake's service/ answer; Laravel uses the first matching stub, so a later Http::fake cannot override it. */
function fakeGeneralReceiptService(mixed $response): void
{
    Http::swap(new Factory);
    Http::fake([VfmsHttp::TEST_URL.'service/' => $response]);
    VfmsHttp::fake();
}

/** @return list<OutboxMessage> */
function generalReceiptMessages(TenantFixture $tenant, string $event): array
{
    return inTenant($tenant->property, fn () => OutboxMessage::query()->where('integration', 'vfms')->where('event', $event)->orderBy('created_at')->orderBy('sequence')->get()->all());
}

// protects=a checked-in guest's own extra stays a guest receipt (CS) with its discount (spec #249); fails_when=the type, document or discount changes; no_claim=Live acceptance.
test('a checked-in guest paying for themselves gets a guest receipt with the line discount', function () {
    $t = twoTenants()->a;
    $reservation = generalReceiptStay($t);
    $folio = inTenant($t->property, fn () => Folio::query()->sole());
    $posting = postGeneralReceiptExtra($this, $t, $folio, ['quantity' => 2, 'unit_price' => 5000, 'discount' => 1000]);

    [$message] = generalReceiptMessages($t, 'services');
    expect($message->payload['body'])->toEqual([
        'documentNumber' => 'P1234567',
        'service' => [['itemName' => 'LUNCH', 'price' => 50, 'quantity' => 2, 'discount' => 10]],
        'referenceNumber' => $message->payload['body']['referenceNumber'],
        'type' => 'CS', 'currency' => 'USD',
    ])->and($posting->discount)->toBe(1000)
        ->and($posting->amount)->toBe(9000)
        ->and($posting->vfms_receipt_number)->toBe('TEST-RECEIPT-169');
});

// protects=an extra posted after check-out gets a general receipt in the guest's name, never their document (spec #249); fails_when=it is skipped, sent as CS or carries the passport; no_claim=Live acceptance.
test('a checked-out guest\'s extra gets a general receipt in the guest\'s full name', function () {
    $t = twoTenants()->a;
    $reservation = generalReceiptStay($t);
    inTenant($t->property, fn () => $reservation->update(['status' => ReservationStatus::CheckedOut]));
    $folio = inTenant($t->property, fn () => Folio::query()->sole());
    $posting = postGeneralReceiptExtra($this, $t, $folio, ['description' => 'LATE BAR', 'quantity' => 1, 'unit_price' => 1500]);

    [$message] = generalReceiptMessages($t, 'generalReceipt');
    expect($message->status)->toBe('delivered')
        ->and($message->payload['request_type'])->toBe('ACCOMMODATION_ADD_SERVICE')
        ->and($message->payload['path'])->toBe('service/')
        ->and($message->subject_id)->toBe($reservation->id)
        ->and($message->payload['body'])->toEqual([
            'documentNumber' => 'Anna Berg',
            'service' => [['itemName' => 'LATE BAR', 'price' => 15, 'quantity' => 1, 'discount' => 0]],
            'referenceNumber' => $message->payload['body']['referenceNumber'],
            'type' => 'WS', 'currency' => 'USD',
        ])
        ->and($message->payload['body']['documentNumber'])->not->toBe('P1234567')
        ->and($posting->fresh()->vfms_receipt_number)->toBe('TEST-RECEIPT-169')
        ->and(generalReceiptMessages($t, 'services'))->toBe([]);
});

// protects=a paying-institution stay's extras wait for one institution receipt (spec #249); fails_when=an institution extra files CS or WS at posting; no_claim=institution receipt issue.
test('a paying-institution stay holds its extras unreceipted', function () {
    $t = twoTenants()->a;
    generalReceiptStay($t, ['paying_institution_type' => 'government', 'paying_institution_name' => 'Ministry of Health', 'paying_institution_zrb_number' => 'WA2400117']);
    $folio = inTenant($t->property, fn () => Folio::query()->sole());
    $posting = postGeneralReceiptExtra($this, $t, $folio, []);

    expect(generalReceiptMessages($t, 'services'))->toBe([])
        ->and(generalReceiptMessages($t, 'generalReceipt'))->toBe([])
        ->and($posting->fresh()->vfms_receipt_number)->toBeNull()
        ->and($posting->fresh()->general_receipt_id)->toBeNull();
});

// protects=a confirmed stay's extra is not filed (spec #249 out of scope); fails_when=a pre-arrival extra files anything; no_claim=fiscal exception listing.
test('a confirmed stay\'s extra is not filed', function () {
    $t = twoTenants()->a;
    generalReceiptStay($t, checkIn: false);
    $folio = inTenant($t->property, fn () => Folio::query()->sole());
    postGeneralReceiptExtra($this, $t, $folio, []);

    expect(inTenant($t->property, fn () => OutboxMessage::query()->where('integration', 'vfms')->count()))->toBe(0);
});

/** A two-room government group, both rooms checked in, Anna Berg in the first room. */
function generalReceiptGroup(TenantFixture $tenant): array
{
    return inTenant($tenant->property, function () use ($tenant) {
        $form = BookingForm::details($tenant->owner);
        app(CreateRoom::class)->handle($tenant->owner, InventoryForm::room($form['room_type_id'], ['number' => '102']));
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        app(CreateReservation::class)->handle($tenant->owner, [
            ...$form, 'rooms' => 2, 'adults' => 2, 'children' => 0, 'distribute_guests' => true, 'nightly_rate' => 11000, 'meal_plan' => 'bed-and-breakfast',
            'paying_institution_type' => 'government', 'paying_institution_name' => 'Ministry of Health', 'paying_institution_zrb_number' => 'WA2400117',
        ]);
        $rooms = Room::query()->orderBy('number')->get();

        return Reservation::query()->orderBy('reservation_number')->get()->map(function (Reservation $member, int $index) use ($tenant, $rooms) {
            $guest = app(CreateGuest::class)->handle($tenant->owner, ['name' => ['Anna Berg', 'Ola Berg'][$index], 'documents' => [['type' => 'passport', 'number' => ['P1234567', 'P7654321'][$index], 'nationality' => 'DE']]]);
            ReservationGuest::query()->create(['reservation_id' => $member->id, 'guest_id' => $guest->id]);
            app(AssignRoom::class)->handle($tenant->owner, $member, $rooms[$index]->id);

            return app(CheckIn::class)->handle($tenant->owner, $member->fresh());
        })->all();
    });
}

function issueGeneralReceiptAs($test, TenantFixture $tenant, Reservation $reservation)
{
    return $test->actingAs($tenant->owner)->post(route('reservations.general-receipt.store', $reservation), ['_property' => $tenant->property->id]);
}

// protects=one institution receipt per currency for the whole group, in the institution's name (spec #249); fails_when=the body differs, a room is missed or a document number is sent; no_claim=Live acceptance.
test('issuing an institution receipt sends one WS per currency for the whole group', function () {
    $t = twoTenants()->a;
    [$first, $second] = generalReceiptGroup($t);
    [$usd, $tzs] = inTenant($t->property, fn () => [Folio::query()->where('reservation_id', $first->id)->sole(), app(OpenFolio::class)->handle($t->owner, $second, 'TZS')]);
    $usdFolio2 = inTenant($t->property, fn () => Folio::query()->where('reservation_id', $second->id)->where('currency', 'USD')->sole());
    $dinner = postGeneralReceiptExtra($this, $t, $usd, ['description' => 'DINNER', 'quantity' => 10, 'unit_price' => 1800, 'discount' => 1000]);
    $hall = postGeneralReceiptExtra($this, $t, $usdFolio2, ['category' => 'other', 'description' => 'CONFERENCE HALL', 'quantity' => 1, 'unit_price' => 15000]);
    $lunch = postGeneralReceiptExtra($this, $t, $tzs, ['description' => 'LUNCH', 'quantity' => 4, 'unit_price' => 25000]);

    issueGeneralReceiptAs($this, $t, $second)->assertRedirect()->assertSessionHasNoErrors();

    $messages = generalReceiptMessages($t, 'generalReceipt');
    expect($messages)->toHaveCount(2)
        ->and(collect($messages)->pluck('subject_id')->unique()->all())->toBe([$second->id])
        ->and(collect($messages)->pluck('status')->unique()->all())->toBe(['delivered'])
        ->and(collect($messages)->map(fn ($message) => array_diff_key($message->payload['body'], ['referenceNumber' => true]))->all())->toEqual([
            ['documentNumber' => 'Ministry of Health', 'type' => 'WS', 'currency' => 'USD', 'service' => [
                ['itemName' => 'DINNER', 'price' => 18, 'quantity' => 10, 'discount' => 10],
                ['itemName' => 'CONFERENCE HALL', 'price' => 150, 'quantity' => 1, 'discount' => 0],
            ]],
            ['documentNumber' => 'Ministry of Health', 'type' => 'WS', 'currency' => 'TZS', 'service' => [
                ['itemName' => 'LUNCH', 'price' => 25000, 'quantity' => 4, 'discount' => 0],
            ]],
        ])
        ->and($messages[0]->idempotency_key)->toStartWith('vfms-ws:'.$messages[0]->payload['receipt_id'].':')
        ->and($messages[0]->payload['body']['referenceNumber'])->not->toBe($messages[1]->payload['body']['referenceNumber'])
        ->and(json_encode(collect($messages)->pluck('payload')))->not->toContain('P1234567')->not->toContain('P7654321');
    inTenant($t->property, function () use ($dinner, $hall, $lunch, $messages) {
        expect([$dinner->fresh()->general_receipt_id, $hall->fresh()->general_receipt_id, $lunch->fresh()->general_receipt_id])->toBe([$messages[0]->id, $messages[0]->id, $messages[1]->id])
            ->and($dinner->fresh()->vfms_receipt_number)->toBe('TEST-RECEIPT-169')
            ->and($lunch->fresh()->vfms_receipt_number)->toBe('TEST-RECEIPT-169');
        $document = IssuedDocument::query()->where('type', 'general_receipt')->where('currency', 'USD')->sole();
        expect($document->only(['number', 'amount', 'guest_name', 'reservation_id', 'mode']))->toEqual([
            'number' => 'TEST-RECEIPT-169', 'amount' => 32000, 'guest_name' => 'Ministry of Health', 'reservation_id' => $messages[0]->subject_id, 'mode' => 'sandbox',
        ])->and($document->getAttribute('viewer_url'))->toEndWith('/receipt-form/TEST-RECEIPT-169');
    });
});

// protects=an issued extra never reaches a second receipt (spec #249); fails_when=a second issue resends claimed extras, or issuing with nothing held succeeds; no_claim=concurrent requests (see the MySQL test).
test('a second issue covers only the extras posted since', function () {
    $t = twoTenants()->a;
    [$first] = generalReceiptGroup($t);
    $folio = inTenant($t->property, fn () => Folio::query()->where('reservation_id', $first->id)->sole());
    postGeneralReceiptExtra($this, $t, $folio, ['description' => 'DINNER']);
    issueGeneralReceiptAs($this, $t, $first)->assertSessionHasNoErrors();
    issueGeneralReceiptAs($this, $t, $first)->assertSessionHasErrors(['general_receipt' => 'Nothing is waiting for a ZRA receipt.']);
    $bar = postGeneralReceiptExtra($this, $t, $folio, ['description' => 'BAR']);
    $reversed = postGeneralReceiptExtra($this, $t, $folio, ['description' => 'WRONG']);
    $this->post(route('postings.reverse', $reversed), ['_property' => $t->property->id, 'reason' => 'Posted twice'])->assertSessionHasNoErrors();
    issueGeneralReceiptAs($this, $t, $first)->assertSessionHasNoErrors();

    $messages = generalReceiptMessages($t, 'generalReceipt');
    expect($messages)->toHaveCount(2)
        ->and(array_column($messages[1]->payload['body']['service'], 'itemName'))->toBe(['BAR'])
        ->and($messages[1]->payload['posting_ids'])->toBe([$bar->id]);
});

// protects=ZRA refusal returns extras to "not yet receipted" and unknown keeps them claimed (spec #249); fails_when=a refused claim stays or an unknown claim is released; no_claim=unknown reconciliation.
test('a refused receipt releases its extras and an unknown one keeps them', function (int $status, array $body, string $outcome, bool $released) {
    $t = twoTenants()->a;
    [$first] = generalReceiptGroup($t);
    $folio = inTenant($t->property, fn () => Folio::query()->where('reservation_id', $first->id)->sole());
    $dinner = postGeneralReceiptExtra($this, $t, $folio, ['description' => 'DINNER']);
    fakeGeneralReceiptService(Http::response($body, $status));
    issueGeneralReceiptAs($this, $t, $first)->assertSessionHasNoErrors();

    [$message] = generalReceiptMessages($t, 'generalReceipt');
    expect($message->status)->toBe($outcome)
        ->and(inTenant($t->property, fn () => $dinner->fresh()->general_receipt_id))->toBe($released ? null : $message->id)
        ->and(inTenant($t->property, fn () => $dinner->fresh()->vfms_receipt_number))->toBeNull();
})->with([
    'refused' => [200, ['statusCode' => 5000, 'statusMessage' => 'Invalid item'], 'rejected', true],
    'unknown' => [503, [], 'unknown', false],
]);

// protects=Re-file of a refused general receipt reclaims and resends its own extras (spec #249); fails_when=Re-file fails or loses the claim; no_claim=a changed set of extras.
test('re-filing a refused receipt reclaims its extras and records the receipt', function () {
    $t = twoTenants()->a;
    [$first] = generalReceiptGroup($t);
    $folio = inTenant($t->property, fn () => Folio::query()->where('reservation_id', $first->id)->sole());
    $dinner = postGeneralReceiptExtra($this, $t, $folio, ['description' => 'DINNER']);
    fakeGeneralReceiptService(Http::sequence()->push(['statusCode' => 5000, 'statusMessage' => 'Invalid item'])->push(['receiptNumber' => 'WS-REFILED']));
    issueGeneralReceiptAs($this, $t, $first);
    [$message] = generalReceiptMessages($t, 'generalReceipt');
    issueGeneralReceiptAs($this, $t, $first)->assertSessionHasErrors('general_receipt');

    $this->post(route('reservations.vfms.refile', [$first, $message]), ['_property' => $t->property->id, 'reason' => 'Item name fixed'])->assertSessionHasNoErrors();

    expect($message->fresh()->status)->toBe('delivered')
        ->and(inTenant($t->property, fn () => $dinner->fresh()->only(['general_receipt_id', 'vfms_receipt_number'])))->toBe(['general_receipt_id' => $message->id, 'vfms_receipt_number' => 'WS-REFILED']);
});

// protects=the group's last check-out issues whatever is still held (spec #249); fails_when=an earlier room's check-out issues or the last one forgets; no_claim=automatic check-out jobs.
test('the last room of an institution group to check out issues what is still held', function () {
    $t = twoTenants()->a;
    [$first, $second] = generalReceiptGroup($t);
    $folio = inTenant($t->property, fn () => Folio::query()->where('reservation_id', $first->id)->sole());
    postGeneralReceiptExtra($this, $t, $folio, ['description' => 'DINNER']);
    foreach (['2026-12-10', '2026-12-11'] as $date) {
        inTenant($t->property, fn () => app(CloseBusinessDate::class)->handle($t->owner, $t->property->fresh(), $date));
    }

    $this->post(route('reservations.check-out', $first), ['_property' => $t->property->id, 'override_reason' => 'Ministry pays later'])->assertSessionHasNoErrors();
    expect(generalReceiptMessages($t, 'generalReceipt'))->toBe([]);
    $this->post(route('reservations.check-out', $second), ['_property' => $t->property->id, 'override_reason' => 'Ministry pays later'])->assertSessionHasNoErrors();

    $messages = generalReceiptMessages($t, 'generalReceipt');
    expect($messages)->toHaveCount(1)
        ->and($messages[0]->status)->toBe('delivered')
        ->and(array_column($messages[0]->payload['body']['service'], 'itemName'))->toBe(['DINNER']);
});

// protects=only the group's own property is claimed, and nothing is filed outside VFMS (spec #249 multi-tenancy); fails_when=a sibling's extras are claimed or a Mainland/demo property files; no_claim=cross-organization isolation (Isolation coverage).
test('a sibling property\'s extras are never claimed, and Mainland and demo properties file nothing', function () {
    $t = twoTenants()->a;
    [$first] = generalReceiptGroup($t);
    inTenant($t->property, fn () => $first->update(['booking_group_id' => $groupId = $first->booking_group_id]));
    $sibling = inTenant($t->otherProperty, function () use ($t, $first) {
        $form = BookingForm::details($t->owner);
        $stay = app(CreateReservation::class)->handle($t->owner, [...$form, 'adults' => 1, 'children' => 0,
            'paying_institution_type' => 'government', 'paying_institution_name' => 'Ministry of Health', 'paying_institution_zrb_number' => 'WA2400117']);
        $stay->forceFill(['booking_group_id' => $first->booking_group_id, 'status' => ReservationStatus::CheckedIn])->save();

        return app(PostExtraCharge::class)->handle($t->owner, Folio::query()->sole(), ['category' => 'bar', 'description' => 'SIBLING BAR', 'quantity' => 1, 'unit_price' => 900]);
    });
    $folio = inTenant($t->property, fn () => Folio::query()->where('reservation_id', $first->id)->sole());
    postGeneralReceiptExtra($this, $t, $folio, ['description' => 'DINNER']);
    issueGeneralReceiptAs($this, $t, $first)->assertSessionHasNoErrors();

    [$message] = generalReceiptMessages($t, 'generalReceipt');
    expect(array_column($message->payload['body']['service'], 'itemName'))->toBe(['DINNER'])
        ->and(inTenant($t->otherProperty, fn () => $sibling->fresh()->general_receipt_id))->toBeNull()
        ->and(inTenant($t->otherProperty, fn () => OutboxMessage::query()->where('integration', 'vfms')->count()))->toBe(0);

    DB::table('properties')->where('id', $t->property->id)->update(['demo' => true]);
    postGeneralReceiptExtra($this, $t, $folio, ['description' => 'DEMO BAR']);
    issueGeneralReceiptAs($this, $t, $first)->assertSessionHasErrors('general_receipt');
    expect(generalReceiptMessages($t, 'generalReceipt'))->toHaveCount(1);
});

// protects=two Sandbox properties never reuse a reference on ZRA's shared test unit (spec #249); fails_when=both send the same referenceNumber; no_claim=Live, where each hotel has its own unit.
test('two Sandbox properties send distinct general receipt references', function () {
    $t = twoTenants();
    foreach ([$t->a, $t->b] as $tenant) {
        [$first] = generalReceiptGroup($tenant);
        $folio = inTenant($tenant->property, fn () => Folio::query()->where('reservation_id', $first->id)->sole());
        postGeneralReceiptExtra($this, $tenant, $folio, ['description' => 'DINNER']);
        issueGeneralReceiptAs($this, $tenant, $first)->assertSessionHasNoErrors();
    }

    $sent = collect(Http::recorded(fn ($request) => $request->url() === VfmsHttp::TEST_URL.'service/'))->map(fn ($pair) => $pair[0]['referenceNumber']);
    expect($sent)->toHaveCount(2)->and($sent->unique())->toHaveCount(2)
        ->and($sent->map(fn (string $reference) => explode('-', $reference)[0])->unique())->toHaveCount(2);
});

// protects=same-name tenants never see or claim each other's receipts (spec #249); fails_when=tenant A's issue claims B's postings or A's page shows B's numbers; no_claim=print views (#252).
test('two tenants with the same institution and guest names stay apart', function () {
    $t = twoTenants();
    [$a] = generalReceiptGroup($t->a);
    [$b] = generalReceiptGroup($t->b);
    $bDinner = inTenant($t->b->property, fn () => app(PostExtraCharge::class)->handle($t->b->owner, Folio::query()->where('reservation_id', $b->id)->sole(), ['category' => 'restaurant', 'description' => 'DINNER', 'quantity' => 1, 'unit_price' => 1000]));
    postGeneralReceiptExtra($this, $t->a, inTenant($t->a->property, fn () => Folio::query()->where('reservation_id', $a->id)->sole()), ['description' => 'DINNER']);
    issueGeneralReceiptAs($this, $t->a, $a)->assertSessionHasNoErrors();

    expect(inTenant($t->b->property, fn () => $bDinner->fresh()->only(['general_receipt_id', 'vfms_receipt_number'])))->toBe(['general_receipt_id' => null, 'vfms_receipt_number' => null])
        ->and(generalReceiptMessages($t->b, 'generalReceipt'))->toBe([]);
    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->get(route('reservations.show', $a))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('generalReceipt', null));
});

// protects=a reversed receipted extra is listed for the accountant, since ZRA cannot void it (spec #249); fails_when=the reversal is not listed or lacks the receipt number; no_claim=refunds.
test('reversing a receipted extra lists a fiscal exception with its receipt number', function () {
    $t = twoTenants()->a;
    $reservation = generalReceiptStay($t);
    $folio = inTenant($t->property, fn () => Folio::query()->sole());
    $posting = postGeneralReceiptExtra($this, $t, $folio, ['description' => 'LUNCH']);
    $this->post(route('postings.reverse', $posting), ['_property' => $t->property->id, 'reason' => 'Wrong room'])->assertSessionHasNoErrors();

    $this->withSession(['tenancy.organization_id' => $t->organization->id, 'tenancy.property_id' => $t->property->id])
        ->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
        ->where('fiscalExceptions.0.reservationNumber', $reservation->reservation_number)
        ->where('fiscalExceptions.0.kind', 'Receipted extra reversed')
        ->where('fiscalExceptions.0.reason', 'ZRA receipt TEST-RECEIPT-169: Wrong room'));
});
