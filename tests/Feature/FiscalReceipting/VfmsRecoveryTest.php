<?php

use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\FrontDesk\Actions\AddGuest;
use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\FrontDesk\Actions\CheckOut;
use App\Domains\FrontDesk\Actions\CloseBusinessDate;
use App\Domains\FrontDesk\Actions\MoveRoom;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Support\Outbox\DeliveryEngine;
use App\Support\Outbox\OutboxCorrectionContext;
use App\Support\Outbox\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;
use Tests\Support\TenantFixture;
use Tests\Support\VfmsHttp;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam'));
    VfmsHttp::fake();
    Http::swap(new Factory);
    Http::preventStrayRequests();
});

function vfmsRecoveryStay(TenantFixture $tenant): Reservation
{
    return inTenant($tenant->property, function () use ($tenant) {
        $details = BookingForm::details($tenant->owner);
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        $reservation = app(CreateReservation::class)->handle($tenant->owner, [...$details, 'room_id' => Room::query()->sole()->id]);
        $guest = app(CreateGuest::class)->handle($tenant->owner, ['name' => 'Recovery Guest', 'documents' => [['type' => 'passport', 'number' => 'RECOVERY-DOC-173', 'nationality' => 'DE']]]);
        ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);

        return app(CheckIn::class)->handle($tenant->owner, $reservation);
    });
}

function vfmsRecoveryMessage(TenantFixture $tenant): OutboxMessage
{
    return inTenant($tenant->property, fn () => OutboxMessage::query()->where('integration', 'vfms')->orderBy('sequence')->firstOrFail());
}

// protects=confirmed rejections can be rebuilt without replacing attempted evidence (#173); fails_when=the old room/reference is resent or blocked successors remain stuck; no_claim=Live key reuse or room-move acceptance.
test('refiling a rejected check-in rotates its reference and releases its rebuilt successors', function () {
    $tenant = twoTenants()->a;
    Http::fake([
        VfmsHttp::TEST_URL.'accommodation/' => fn (Request $request) => Http::response(
            str_ends_with($request['referenceNumber'], 'R1')
                ? ['responseNumber' => 'AC-173']
                : ['statusCode' => 5000, 'statusMessage' => 'The Room ID 1 Is not Empty'],
        ),
        VfmsHttp::TEST_URL.'guest/checkout/' => Http::response(['reportNo' => 'CO-173', 'totalAmount' => 220, 'totalTax' => 0, 'infrastructureTax' => 0]),
        VfmsHttp::TEST_URL.'change/room/' => Http::response(['responseNumber' => 'MOVE-173']),
    ]);
    $reservation = vfmsRecoveryStay($tenant);
    $message = vfmsRecoveryMessage($tenant);
    $original = $message->payload;
    expect($message->status)->toBe('rejected');
    $room = inTenant($tenant->property, function () use ($tenant, $reservation) {
        $room = app(CreateRoom::class)->handle($tenant->owner, InventoryForm::room($reservation->room_type_id, ['number' => '102']));
        RoomStatus::query()->where('room_id', $room->id)->update(['status' => HousekeepingStatus::Clean]);
        app(MoveRoom::class)->handle($tenant->owner, $reservation, $room->id);
        foreach (['2026-12-10', '2026-12-11'] as $date) {
            app(CloseBusinessDate::class)->handle($tenant->owner, $tenant->property->fresh(), $date);
        }
        app(CheckOut::class)->handle($tenant->owner, $reservation->fresh(), 'Settled at the desk');

        return $room;
    });
    inTenant($tenant->property, function () {
        foreach (OutboxMessage::query()->where('integration', 'vfms')->orderBy('sequence')->get() as $message) {
            app(DeliveryEngine::class)->deliver($message->id);
        }
        expect(OutboxMessage::query()->where('event', 'checkout')->sole()->status)->toBe('blocked');
    });

    $this->actingAs($tenant->owner)->post(route('reservations.vfms.refile', [$reservation, $message]), [
        '_property' => $tenant->property->id, 'reason' => 'Corrected the occupied room assignment',
    ])->assertRedirect()->assertSessionHasNoErrors();
    inTenant($tenant->property, function () use ($message, $original, $room) {
        $message = $message->fresh();
        expect($message->payload['body']['roomId'])->toBe($room->vfms_room_id)
            ->and($message->payload['body']['referenceNumber'])->toBe($original['body']['referenceNumber'].'R1')
            ->and($message->payload['body']['checkInDate'])->toBe($original['body']['checkInDate'])
            ->and($message->revisions()->sole()->payload)->toEqual($original)
            ->and(json_decode($message->attempts()->where('attempt_number', 1)->sole()->request_body, true))->toEqual($original);
        foreach (OutboxMessage::query()->where('integration', 'vfms')->orderBy('sequence')->get() as $queued) {
            app(DeliveryEngine::class)->deliver($queued->id);
        }
        expect(OutboxMessage::query()->where('integration', 'vfms')->pluck('status')->unique()->all())->toBe(['delivered'])
            ->and(OutboxMessage::query()->where('event', 'checkout')->sole()->revisions()->count())->toBe(1);
    });
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/accommodation/')
        && str_ends_with($request['referenceNumber'], 'R1') && $request['roomName'] === '102');
});

// protects=only existing recovery roles may re-file, with refused forms audited (#173); fails_when=Reception/Finance can correct, or a denial has no audit; no_claim=custom roles not in this role matrix.
test('VFMS refile follows recovery permissions and audits denied roles', function (OrganizationRole $role) {
    $tenant = twoTenants()->a;
    Http::fake([VfmsHttp::TEST_URL.'*' => Http::response(['statusCode' => 5000, 'statusMessage' => 'The Room ID 1 Is not Empty'])]);
    $reservation = vfmsRecoveryStay($tenant);
    $message = vfmsRecoveryMessage($tenant);
    $actor = $role === OrganizationRole::Owner ? $tenant->owner : $tenant->member($role);
    $allowed = in_array($role, [OrganizationRole::Owner, OrganizationRole::PropertyManager], true);
    if ($role !== OrganizationRole::Housekeeping) {
        $this->actingAs($actor)->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id])
            ->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page
            ->where('vfmsRefusal.message', 'The Room ID 1 Is not Empty')
            ->where('vfmsRefusal.refileUrl', $allowed ? route('reservations.vfms.refile', [$reservation, $message]) : null));
    }
    $response = $this->actingAs($actor)->post(route('reservations.vfms.refile', [$reservation, $message]), [
        '_property' => $tenant->property->id, 'reason' => 'Review the corrected stay',
    ]);
    if ($allowed) {
        $response->assertRedirect()->assertSessionHasNoErrors();
        expect(inTenant($tenant->property, fn () => $message->revisions()->count()))->toBe(1);
    } else {
        $response->assertForbidden();
        expect(inTenant($tenant->property, fn () => $message->revisions()->count()))->toBe(0)
            ->and(DB::table('activity_log')->where('causer_id', $actor->id)->where('event', 'refused')->exists())->toBeTrue();
    }
})->with(OrganizationRole::cases());

// protects=accepted or uncertain calls never get new wire identities (#173); fails_when=refile or retry sends an already accepted/unknown filing; no_claim=manual provider reconciliation.
test('delivered and unknown filings cannot be refiled or blindly retried', function (string $status, string $operation) {
    $tenant = twoTenants()->a;
    Http::fake([VfmsHttp::TEST_URL.'*' => Http::response($status === 'delivered'
        ? ['responseNumber' => 'AC-173']
        : ['statusCode' => 5000, 'statusMessage' => 'Reference number already exists'])]);
    $reservation = vfmsRecoveryStay($tenant);
    $message = vfmsRecoveryMessage($tenant);
    expect($message->status)->toBe($status);

    $this->actingAs($tenant->owner)->from(route('reservations.show', $reservation))
        ->post(route('reservations.vfms.'.$operation, [$reservation, $message]), [
            '_property' => $tenant->property->id, 'reason' => 'Review this filing',
        ])->assertRedirect()->assertSessionHasErrors('vfms');

    Http::assertSentCount(1);
    expect(inTenant($tenant->property, fn () => $message->fresh()->status))->toBe($status)
        ->and(inTenant($tenant->property, fn () => $message->revisions()->count()))->toBe(0)
        ->and(DB::table('activity_log')->where('subject_id', $message->id)->where('event', 'outbox_recovery_refused')->exists())->toBeTrue();
})->with([
    ['delivered', 'refile'], ['unknown', 'refile'], ['unknown', 'retry'],
]);

// protects=the route cannot substitute a different stay's message (#173); fails_when=a valid property-scoped message is corrected through the wrong reservation; no_claim=cross-organization coverage (isolation suite).
test('a VFMS filing must belong to the reservation in its recovery URL', function () {
    $tenant = twoTenants()->a;
    Http::fake([VfmsHttp::TEST_URL.'*' => Http::response(['statusCode' => 5000, 'statusMessage' => 'The Room ID 1 Is not Empty'])]);
    $reservation = vfmsRecoveryStay($tenant);
    $message = vfmsRecoveryMessage($tenant);
    $other = inTenant($tenant->property, function () use ($reservation) {
        $other = $reservation->replicate();
        $other->reservation_number = 'R-OTHER-173';
        $other->save();

        return $other;
    });

    $this->actingAs($tenant->owner)->post(route('reservations.vfms.refile', [$other, $message]), [
        '_property' => $tenant->property->id, 'reason' => 'Wrong stay',
    ])->assertNotFound();
    expect(inTenant($tenant->property, fn () => $message->revisions()->count()))->toBe(0);
    Http::assertSentCount(1);
});

// protects=organization membership does not authorize another property's filing through the open stay (#173); fails_when=foreign property message IDs bypass property intent; no_claim=cross-organization coverage.
test('VFMS recovery cannot borrow a message from another property of the same organization', function () {
    $tenant = twoTenants()->a;
    Http::fake([VfmsHttp::TEST_URL.'*' => Http::response(['statusCode' => 5000, 'statusMessage' => 'The Room ID 1 Is not Empty'])]);
    $reservation = vfmsRecoveryStay($tenant);
    $message = vfmsRecoveryMessage($tenant);
    $foreign = inTenant($tenant->otherProperty, function () use ($tenant, $message) {
        $other = app(CreateReservation::class)->handle($tenant->owner, BookingForm::details($tenant->owner));
        $foreign = $message->replicate();
        $foreign->forceFill([
            'property_id' => $other->property_id, 'subject_id' => $other->id,
            'stream_key' => hash('sha256', $other->id), 'idempotency_key' => 'foreign:'.$other->id,
            'payload' => [...$message->payload, 'reservation_id' => $other->id],
        ])->save();

        return $foreign;
    });

    $this->actingAs($tenant->owner)->post(route('reservations.vfms.refile', [$reservation, $foreign]), [
        '_property' => $tenant->property->id, 'reason' => 'Wrong property',
    ])->assertNotFound();
    expect(inTenant($tenant->otherProperty, fn () => $foreign->fresh()->status))->toBe('rejected')
        ->and(inTenant($tenant->otherProperty, fn () => $foreign->revisions()->count()))->toBe(0);
    Http::assertSentCount(1);
});

// protects=correction requires an explicit auditable reason (#173); fails_when=blank input rotates the reference; no_claim=provider acceptance.
test('VFMS recovery requires a reason', function () {
    $tenant = twoTenants()->a;
    Http::fake([VfmsHttp::TEST_URL.'*' => Http::response(['statusCode' => 5000, 'statusMessage' => 'The Room ID 1 Is not Empty'])]);
    $reservation = vfmsRecoveryStay($tenant);
    $message = vfmsRecoveryMessage($tenant);

    $this->actingAs($tenant->owner)->post(route('reservations.vfms.refile', [$reservation, $message]), [
        '_property' => $tenant->property->id, 'reason' => ' ',
    ])->assertSessionHasErrors('reason');
    expect(inTenant($tenant->property, fn () => $message->revisions()->count()))->toBe(0);
});

// protects=each confirmed rejection has a distinct wire identity (#173); fails_when=R1 is reused or grows into R1R2; no_claim=provider acceptance.
test('successive confirmed rejections use revision numbers from the original reference', function () {
    $tenant = twoTenants()->a;
    Http::fake([VfmsHttp::TEST_URL.'*' => fn (Request $request) => Http::response(
        str_ends_with($request['referenceNumber'], 'R2') ? ['responseNumber' => 'AC-173'] : ['statusCode' => 5000, 'statusMessage' => 'The Room ID 1 Is not Empty'],
    )]);
    $reservation = vfmsRecoveryStay($tenant);
    $message = vfmsRecoveryMessage($tenant);
    $reference = $message->payload['body']['referenceNumber'];
    foreach ([1, 2] as $revision) {
        $this->actingAs($tenant->owner)->post(route('reservations.vfms.refile', [$reservation, $message]), [
            '_property' => $tenant->property->id, 'reason' => 'Corrected room, revision '.$revision,
        ])->assertSessionHasNoErrors();
        inTenant($tenant->property, fn () => app(DeliveryEngine::class)->deliver($message->id));
        expect(inTenant($tenant->property, fn () => $message->fresh()->payload['body']['referenceNumber']))->toBe($reference.'R'.$revision);
    }
    expect(inTenant($tenant->property, fn () => $message->fresh()->status))->toBe('delivered')
        ->and(inTenant($tenant->property, fn () => $message->revisions()->count()))->toBe(2);
    Http::assertSentCount(3);
});

// protects=re-filing never silently changes environment (#173); fails_when=a Sandbox rejection becomes a Live fiscal submission; no_claim=mode-switch UI.
test('VFMS correction refuses an environment change', function () {
    $tenant = twoTenants()->a;
    Http::fake([VfmsHttp::TEST_URL.'*' => Http::response(['statusCode' => 5000, 'statusMessage' => 'The Room ID 1 Is not Empty'])]);
    $reservation = vfmsRecoveryStay($tenant);
    $message = vfmsRecoveryMessage($tenant);
    inTenant($tenant->property, fn () => VfmsCredential::query()->create(['mode' => VfmsMode::Live]));

    $this->actingAs($tenant->owner)->post(route('reservations.vfms.refile', [$reservation, $message]), [
        '_property' => $tenant->property->id, 'reason' => 'Wrong environment',
    ])->assertSessionHasErrors('vfms');
    expect(inTenant($tenant->property, fn () => $message->revisions()->count()))->toBe(0);
    Http::assertSentCount(1);
});

// protects=provider errors do not expose identity documents in the stay banner (#173); fails_when=an echoed document number reaches a Finance viewer; no_claim=raw-evidence access.
test('VFMS refusal summaries do not expose provider identity details', function () {
    $tenant = twoTenants()->a;
    Http::fake([VfmsHttp::TEST_URL.'*' => Http::response(['statusCode' => 5000, 'statusMessage' => 'Guest Document RECOVERY-DOC-173 Not Found'])]);
    $reservation = vfmsRecoveryStay($tenant);

    $this->actingAs($tenant->member(OrganizationRole::Finance))->get(route('reservations.show', $reservation))
        ->assertOk()->assertDontSee('RECOVERY-DOC-173')
        ->assertInertia(fn (Assert $page) => $page
            ->where('vfmsRefusal.message', 'ZRA refused this filing. Review the stay and its VFMS settings before re-filing.')
            ->where('vfmsRefusal.refileUrl', null));
});

// protects=an added guest is not filed twice through a rebuilt check-in and its blocked successor (#173); fails_when=ambiguous membership history is submitted; no_claim=historical guest reconstruction.
test('refiling check-in with later guest additions requires reconciliation', function () {
    $tenant = twoTenants()->a;
    Http::fake([VfmsHttp::TEST_URL.'*' => Http::response(['statusCode' => 5000, 'statusMessage' => 'The Room ID 1 Is not Empty'])]);
    $reservation = vfmsRecoveryStay($tenant);
    $message = vfmsRecoveryMessage($tenant);
    inTenant($tenant->property, function () use ($tenant, $reservation) {
        $guest = app(CreateGuest::class)->handle($tenant->owner, ['name' => 'Added Guest', 'documents' => [['type' => 'passport', 'number' => 'ADDED-DOC-173', 'nationality' => 'DE']]]);
        app(AddGuest::class)->handle($tenant->owner, $reservation, ['guest_id' => $guest->id]);
    });

    $this->actingAs($tenant->owner)->post(route('reservations.vfms.refile', [$reservation, $message]), [
        '_property' => $tenant->property->id, 'reason' => 'Guest membership changed',
    ])->assertSessionHasErrors('vfms');
    Http::assertSentCount(1);
    expect(inTenant($tenant->property, fn () => $message->revisions()->count()))->toBe(0);
});

// protects=the pilot's Live rejection from the old URL is rebuilt with current wire formats (#176); fails_when=refile reuses the old body or endpoint; no_claim=ZRA production acceptance.
test('a Live check-in rejected at the old gateway URL is rebuilt while staying Live', function () {
    $tenant = twoTenants()->a;
    inTenant($tenant->property, fn () => VfmsCredential::query()->create([
        'mode' => VfmsMode::Live, 'integration_id' => 'INT-176', 'registration_id' => 'REG-176', 'token' => 'TOKEN-176',
    ]));
    config(['services.vfms.live_url' => 'https://gateway.zanrevenue.org/api/']);
    Http::fake([
        'https://gateway.zanrevenue.org/api/*' => Http::response('Not Found', 404),
        'https://gateway.zanrevenue.org/vfms/api/accommodation/' => Http::response(['responseNumber' => 'AC-176']),
    ]);
    $reservation = vfmsRecoveryStay($tenant);
    $message = vfmsRecoveryMessage($tenant);
    expect($message->status)->toBe('rejected');
    // Reproduce the stored pre-conformance body, not a mode switch.
    inTenant($tenant->property, function () use ($message) {
        app(OutboxCorrectionContext::class)->run(function () use ($message) {
            $payload = $message->payload;
            $payload['body']['paymentPackage'] = 'room-only';
            $payload['body']['checkInDate'] = '2026-12-10';
            unset($payload['body']['guest'][0]['arrivalDate']);
            $message->payload = $payload;
            $message->save();
        });
    });
    config(['services.vfms.live_url' => 'https://gateway.zanrevenue.org/vfms/api/']);
    $this->actingAs($tenant->owner)->post(route('reservations.vfms.refile', [$reservation, $message]), [
        '_property' => $tenant->property->id, 'reason' => 'Corrected VFMS gateway URL and payload formats',
    ])->assertRedirect()->assertSessionHasNoErrors();
    inTenant($tenant->property, fn () => app(DeliveryEngine::class)->deliver($message->id));
    expect(inTenant($tenant->property, fn () => $message->fresh()->status))->toBe('delivered');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://gateway.zanrevenue.org/vfms/api/accommodation/'
        && $request['paymentPackage'] === 'RO' && $request['checkInDate'] === '2026-12-10T06:00:00.000Z'
        && $request['guest'][0]['arrivalDate'] === '2026-12-10');
});
