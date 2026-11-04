<?php

use App\Domains\FiscalReceipting\Adapters\VfmsAdapter;
use App\Domains\FiscalReceipting\CheckoutReport;
use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\IssuedDocuments;
use App\Domains\FiscalReceipting\Models\IssuedDocument;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\FiscalReceipting\VfmsFilings;
use App\Domains\FiscalReceipting\VfmsRefusal;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Support\Outbox\DeliveryEngine;
use App\Support\Outbox\Outbox;
use App\Support\Outbox\OutboxMessage;
use App\Support\Outbox\OutboxRecovery;
use App\Support\Outbox\RestoreGate;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BookingForm;
use Tests\Support\TenantFixture;

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    config(['services.vfms.live_url' => 'https://vfms-live.example/vfms/api/']);
});

/** Accepted evidence fixture, not a claim of a provider replacement-issuance procedure. */
function correctionDocuments(TenantFixture $tenant, string $type = 'services_receipt'): array
{
    return inTenant($tenant->property, function () use ($tenant, $type) {
        $tenant->property->update(['email' => 'desk@example.test', 'phone' => '+255777123456', 'z_number' => 'Z0123456789-5940']);
        VfmsCredential::for($tenant->property)->fill(['mode' => VfmsMode::Live, 'unit_id' => 232, 'integration_id' => 'CORRECTION-ID', 'registration_id' => 'REG', 'token' => 'SECRET-TOKEN'])->save();
        $stay = app(CreateReservation::class)->handle($tenant->owner, BookingForm::details($tenant->owner));
        foreach (['OLD-232', 'NEW-232'] as $number) {
            $message = DB::transaction(fn () => app(Outbox::class)->record('vfms', $stay, $type === 'services_receipt' ? 'services' : 'checkout', [
                'mode' => 'live', 'reservation_id' => $stay->id, 'posting_id' => 'same-service',
                'request_type' => $type === 'services_receipt' ? 'ACCOMMODATION_ADD_SERVICE' : 'ACCOMMODATION_CHECK_OUT',
                'path' => $type === 'services_receipt' ? 'service/' : 'guest/checkout/',
                'body' => $type === 'services_receipt'
                    ? ['currency' => 'USD', 'documentNumber' => 'PRIVATE-GUEST-ID', 'service' => [['price' => 10, 'quantity' => 1, 'discount' => 0]]]
                    : ['currency' => 'USD', 'documentNumberList' => [['documentNumber' => 'P1234567']]],
            ], 'fixture:'.$number));
            $report = json_decode(file_get_contents(__DIR__.'/../../Fixtures/vfms/checkout.json'), true)[0];
            $response = $type === 'services_receipt' ? ['receiptNumber' => $number, 'businessName' => 'Accepted business'] : [[...$report, 'reportNumber' => $number, 'businessName' => 'Accepted business']];
            $message->forceFill(['status' => 'delivered', 'delivered_at' => now(), 'provider_references' => [
                'mode' => 'live', 'unitId' => 232,
                ...($type === 'services_receipt' ? ['receiptNumber' => $number] : ['reportNumbers' => json_encode([$number])]),
            ]])->save();
            $message->attempts()->create(['attempt_number' => 1, 'outcome' => 'accepted', 'request_body' => json_encode($message->payload), 'response_body' => json_encode(['status' => 200, 'body' => $response])]);
            app(IssuedDocuments::class)->project($message);
        }

        return [$stay, IssuedDocument::query()->where('number', 'OLD-232')->sole(), IssuedDocument::query()->where('number', 'NEW-232')->sole()];
    });
}

// Section 3 documented-code interpretation only: this acknowledgement has not been verified against ZRA.
test('a manager records the selected issued document pair and sends exact ERROR_SAVE without changing original evidence', function (string $type) {
    $tenant = twoTenants()->a;
    [$stay, $old, $new] = correctionDocuments($tenant, $type);
    $before = DB::table('issued_documents')->orderBy('id')->get()->toJson();
    Http::fake(['https://vfms-live.example/vfms/error-management/new' => Http::response(['status' => 201, 'statusMessage' => 'Saved'])]);

    $this->actingAs($tenant->owner)->post(route('issued-documents.corrections.store', $old), [
        '_property' => $tenant->property->id, 'replacement_id' => $new->id, 'reason' => 'Wrong particulars', 'confirmed' => true,
        'old_receipt_number' => 'UNTRUSTED-OLD', 'new_receipt_number' => 'UNTRUSTED-NEW',
    ])->assertRedirect();
    $message = inTenant($tenant->property, fn () => OutboxMessage::query()->where('event', 'errorSave')->sole());
    inTenant($tenant->property, fn () => app(DeliveryEngine::class)->deliver($message->id));

    Http::assertSent(fn (Request $request) => $request->url() === 'https://vfms-live.example/vfms/error-management/new'
        && $request->header('Content-type') === ['application/json']
        && $request->header('vfms-request-type') === ['ERROR_SAVE']
        && $request->header('vfms-intergration-id') === ['CORRECTION-ID']
        && $request->header('vfms-token-id') === ['SECRET-TOKEN']
        && $request->data() === [
            'b_unit_name' => 'Accepted business', 'email' => 'desk@example.test', 'phone_no' => '+255777123456',
            'zrb_number' => 'Z0123456789-5940', 'unitId' => 232,
            'old_receipt_number' => 'OLD-232', 'new_receipt_number' => 'NEW-232', 'reasons' => 'Wrong particulars',
        ]);
    expect(inTenant($tenant->property, fn () => $message->fresh()->status))->toBe('delivered')
        ->and($message->sequence)->toBe(3)
        ->and(DB::table('issued_documents')->orderBy('id')->get()->toJson())->toBe($before)
        ->and(DB::table('document_corrections')->count())->toBe(1)
        ->and(DB::table('postings')->count())->toBe(0)
        ->and(DB::table('reservation_lifecycle_events')->count())->toBe(1);
    $pair = DB::table('document_corrections')->sole();
    expect($pair->original_document_id)->toBe($old->id)->and($pair->replacement_document_id)->toBe($new->id)
        ->and($pair->actor_id)->toBe($tenant->owner->id)->and($pair->outbox_message_id)->toBe($message->id)
        ->and($pair->reason)->not->toContain('Wrong particulars');
    expect(DB::table('activity_log')->where('event', 'document_correction_recorded')->count())->toBe(1);
    Http::assertSentCount(1);
})->with(['services_receipt', 'checkout_report']);

function submitCorrection($test, TenantFixture $tenant, IssuedDocument $old, IssuedDocument $new)
{
    return $test->actingAs($tenant->owner)->post(route('issued-documents.corrections.store', $old), [
        '_property' => $tenant->property->id, 'replacement_id' => $new->id, 'reason' => 'Wrong particulars', 'confirmed' => true,
    ]);
}

// Provisional acknowledgements: documented status-code interpretation, not provider-observed fixtures.
test('ERROR_SAVE separates documented acceptance refusal and uncertainty without replay', function (mixed $body, int $http, string $outcome) {
    $tenant = twoTenants()->a;
    [, $old, $new] = correctionDocuments($tenant);
    Http::fake(['https://vfms-live.example/vfms/error-management/new' => Http::response($body, $http)]);
    submitCorrection($this, $tenant, $old, $new)->assertRedirect();
    $message = inTenant($tenant->property, fn () => OutboxMessage::query()->where('event', 'errorSave')->sole());
    inTenant($tenant->property, function () use ($message, $outcome) {
        app(DeliveryEngine::class)->deliver($message->id);
        app(DeliveryEngine::class)->deliver($message->id);
        expect($message->fresh()->status)->toBe($outcome)->and($message->fresh()->attempt_count)->toBe(1);
    });
    submitCorrection($this, $tenant, $old, $new)->assertSessionHasErrors('correction');
    expect(DB::table('document_corrections')->count())->toBe(1);
    Http::assertSentCount(1);
})->with([
    '200 integer' => [['status' => 200], 200, 'delivered'],
    '201 string' => [['status' => '201'], 200, 'delivered'],
    'HTTP alone' => [[], 200, 'unknown'],
    'statusCode alone' => [['statusCode' => 200], 200, 'unknown'],
    '5000' => [['status' => 5000], 200, 'rejected'],
    'legacy 5000' => [['statusCode' => 5000], 200, 'rejected'],
    'duplicate success' => [['status' => 200, 'message' => 'Already exists'], 200, 'unknown'],
    'duplicate refusal' => [['status' => 400, 'message' => 'Duplicate'], 400, 'unknown'],
    'contradiction' => [['status' => 200, 'statusCode' => 5000], 200, 'unknown'],
    'contradictory message' => [['status' => 200, 'message' => 'Validation failed'], 200, 'unknown'],
    'refusal' => [['status' => '400', 'message' => 'Invalid number'], 200, 'rejected'],
    'unknown code' => [['status' => 123], 200, 'unknown'],
    '5xx success' => [['status' => 200], 503, 'unknown'],
    'malformed' => ['{broken', 200, 'unknown'],
    'ambiguous list' => [[['status' => 200]], 200, 'unknown'],
    'malformed refusal' => ['bad gateway', 400, 'unknown'],
]);

test('queued corrections refuse mode unit demo Mainland or archive changes before HTTP', function (string $change) {
    $tenant = twoTenants()->a;
    [, $old, $new] = correctionDocuments($tenant);
    submitCorrection($this, $tenant, $old, $new)->assertRedirect();
    inTenant($tenant->property, function () use ($tenant, $change) {
        match ($change) {
            'mode' => VfmsCredential::for($tenant->property)->update(['mode' => VfmsMode::Sandbox]),
            'unit' => VfmsCredential::for($tenant->property)->update(['unit_id' => 233]),
            'demo' => DB::table('properties')->where('id', $tenant->property->id)->update(['demo' => true]),
            'Mainland' => $tenant->property->update(['jurisdiction_profile' => 'mainland_tanzania']),
            'archive' => $tenant->property->forceFill(['archived_at' => now()])->save(),
        };
        $message = OutboxMessage::query()->where('event', 'errorSave')->sole();
        app(DeliveryEngine::class)->deliver($message->id);
        expect($message->fresh()->status)->toBe('rejected');
    });
    Http::assertNothingSent();
})->with(['mode', 'unit', 'demo', 'Mainland', 'archive']);

test('accepted filings retain their recorded business unit instead of inferring it at correction time', function () {
    $tenant = twoTenants()->a;
    [$stay] = correctionDocuments($tenant);
    $message = inTenant($tenant->property, fn () => DB::transaction(fn () => app(Outbox::class)->record('vfms', $stay, 'services', [
        'mode' => 'live', 'unit_id' => 232, 'reservation_id' => $stay->id, 'posting_id' => 'missing-posting',
        'request_type' => 'ACCOMMODATION_ADD_SERVICE', 'path' => 'service/',
        'body' => ['currency' => 'USD', 'service' => [['price' => 10, 'quantity' => 1, 'discount' => 0]]],
    ], 'unit-snapshot')));
    // A check-in response avoids financial side effects; the subject's recorded unit still comes from its payload.
    inTenant($tenant->property, fn () => $message->update(['event' => 'accommodation']));
    Http::fake(['https://vfms-live.example/vfms/api/service/' => Http::response(['receiptNumber' => 'UNIT-232'])]);
    inTenant($tenant->property, function () use ($message, $tenant) {
        VfmsCredential::for($tenant->property)->update(['unit_id' => 999]);
        app(DeliveryEngine::class)->deliver($message->id);
        expect($message->fresh()->getAttribute('provider_references')['unitId'] ?? null)->toBe(232);
    });
});

test('an in-flight double submit records one immutable correction and one stable outbox identity', function () {
    $tenant = twoTenants()->a;
    [, $old, $new] = correctionDocuments($tenant);
    $test = $this;
    Http::fake(['https://vfms-live.example/vfms/error-management/new' => function () use ($test, $tenant, $old, $new) {
        submitCorrection($test, $tenant, $old, $new)->assertSessionHasErrors('correction');

        return Http::response(['status' => '200']);
    }]);
    submitCorrection($this, $tenant, $old, $new)->assertRedirect();
    $message = inTenant($tenant->property, fn () => OutboxMessage::query()->where('event', 'errorSave')->sole());
    $key = $message->idempotency_key;
    inTenant($tenant->property, fn () => app(DeliveryEngine::class)->deliver($message->id));
    expect(DB::table('document_corrections')->count())->toBe(1)
        ->and(DB::table('outbox_messages')->where('event', 'errorSave')->count())->toBe(1)
        ->and(DB::table('outbox_messages')->where('id', $message->id)->value('idempotency_key'))->toBe($key);
    Http::assertSentCount(1);
});

test('correction rollback includes its pair outbox and success audit', function () {
    $tenant = twoTenants()->a;
    [, $old, $new] = correctionDocuments($tenant);
    $connection = DB::connection()->getDriverName();
    DB::unprepared($connection === 'sqlite'
        ? "CREATE TRIGGER fail_correction BEFORE INSERT ON document_corrections BEGIN SELECT RAISE(ABORT, 'injected pair failure'); END"
        : "CREATE TRIGGER fail_correction BEFORE INSERT ON document_corrections FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected pair failure'");
    try {
        submitCorrection($this, $tenant, $old, $new);
        expect(DB::table('document_corrections')->count())->toBe(0)
            ->and(DB::table('outbox_messages')->where('event', 'errorSave')->count())->toBe(0)
            ->and(DB::table('activity_log')->where('event', 'document_correction_recorded')->count())->toBe(0);
        Http::assertNothingSent();
    } finally {
        DB::unprepared('DROP TRIGGER fail_correction');
    }
});

test('correction administration cannot hide a previously issued checkout report', function () {
    $tenant = twoTenants()->a;
    [$stay, $old, $new] = correctionDocuments($tenant, 'checkout_report');
    inTenant($tenant->property, function () {
        OutboxMessage::query()->where('event', 'checkout')->get()->each(function ($message) {
            $message->forceFill(['provider_references' => [...$message->getAttribute('provider_references'), 'reportNo' => 'OLD-232', 'totalAmount' => 220, 'roomAmount' => 220, 'extrasAmount' => 0]])->save();
        });
    });
    $before = inTenant($tenant->property, fn () => CheckoutReport::of($stay));
    submitCorrection($this, $tenant, $old, $new)->assertRedirect();
    expect(inTenant($tenant->property, fn () => CheckoutReport::of($stay)))->toEqual($before);
});

test('correction submit refuses unsafe pairs without exposing sensitive evidence', function (string $attack) {
    $t = twoTenants();
    $tenant = $t->a;
    [$stay, $old, $new] = correctionDocuments($tenant, $attack === 'different guest' ? 'checkout_report' : 'services_receipt');
    inTenant($tenant->property, function () use ($tenant, $new, $attack) {
        $source = OutboxMessage::query()->findOrFail($new->outbox_message_id);
        match ($attack) {
            'Sandbox' => VfmsCredential::for($tenant->property)->update(['mode' => VfmsMode::Sandbox]),
            'Mainland' => $tenant->property->update(['jurisdiction_profile' => 'mainland_tanzania']),
            'demo' => DB::table('properties')->where('id', $tenant->property->id)->update(['demo' => true]),
            'different mode' => DB::table('issued_documents')->where('id', $new->id)->update(['mode' => 'sandbox']),
            'different type' => DB::table('issued_documents')->where('id', $new->id)->update(['type' => 'checkout_report']),
            'different service' => DB::table('issued_documents')->where('id', $new->id)->update(['posting_id' => 'other-service']),
            'missing unit' => $source->forceFill(['provider_references' => [...$source->getAttribute('provider_references'), 'unitId' => null]])->save(),
            'different unit' => $source->forceFill(['provider_references' => [...$source->getAttribute('provider_references'), 'unitId' => 999]])->save(),
            'unaccepted evidence' => $source->update(['status' => 'unknown']),
            'different guest' => $source->attempts()->where('outcome', 'accepted')->update(['response_body' => Crypt::encryptString(json_encode(['body' => [[
                ...json_decode(file_get_contents(__DIR__.'/../../Fixtures/vfms/checkout.json'), true)[0],
                'reportNumber' => 'NEW-232', 'guest' => ['fullName' => 'Another guest', 'documentNo' => 'OTHER-PRIVATE-ID'],
            ]]]))]),
            default => null,
        };
    });
    if ($attack === 'self') {
        $new = $old;
    }
    if ($attack === 'foreign replacement') {
        [, , $new] = correctionDocuments($t->b);
    }
    if ($attack === 'sibling replacement') {
        $new = inTenant($tenant->otherProperty, function () use ($new) {
            $message = OutboxMessage::query()->create([
                'integration' => 'vfms', 'subject_type' => 'reservation', 'subject_id' => 'sibling', 'sequence' => 1,
                'stream_key' => hash('sha256', 'sibling'), 'idempotency_key' => 'sibling', 'event' => 'services', 'payload' => [],
            ]);

            return IssuedDocument::query()->create([...$new->getAttributes(), 'outbox_message_id' => $message->id]);
        });
    }
    $response = submitCorrection($this, $tenant, $old, $new)->assertSessionHasErrors('correction');
    expect($response->getContent())->not->toContain('PRIVATE-GUEST-ID', 'P1234567', 'SECRET-TOKEN', 'OTHER-PRIVATE-ID');
    expect(DB::table('document_corrections')->count())->toBe(0)
        ->and(DB::table('outbox_messages')->where('event', 'errorSave')->count())->toBe(0)
        ->and(DB::table('activity_log')->where('event', 'refused')->count())->toBeGreaterThan(0);
    if ($attack === 'Sandbox') {
        $response->assertSessionHasErrors(['correction' => 'ZRA does not provide error correction in Sandbox.']);
    }
    expect(DB::table('activity_log')->where('event', 'refused')->get()->toJson())->not->toContain('PRIVATE-GUEST-ID', 'P1234567', 'SECRET-TOKEN', 'OTHER-PRIVATE-ID');
    Http::assertNothingSent();
})->with(['self', 'Sandbox', 'Mainland', 'demo', 'different mode', 'different type', 'different service', 'missing unit', 'different unit', 'unaccepted evidence', 'different guest', 'foreign replacement', 'sibling replacement']);

test('correction policy grants only OutboxRecover roles and audits sensitive form refusals', function (string $role, bool $allowed) {
    $tenant = twoTenants()->a;
    [, $old, $new] = correctionDocuments($tenant);
    $actor = $tenant->member(OrganizationRole::from($role));
    $response = $this->actingAs($actor)->post(route('issued-documents.corrections.store', $old), [
        '_property' => $tenant->property->id, 'replacement_id' => $new->id, 'reason' => 'Wrong particulars', 'confirmed' => true,
    ]);
    if ($allowed) {
        $response->assertRedirect()->assertSessionDoesntHaveErrors();
        expect(DB::table('document_corrections')->value('actor_id'))->toBe($actor->id);
    } else {
        $response->assertForbidden();
        expect(DB::table('document_corrections')->count())->toBe(0)
            ->and(DB::table('activity_log')->where('event', 'refused')->count())->toBeGreaterThan(0);
    }
    Http::assertNothingSent();
})->with(fn () => array_map(fn ($role) => [$role->value, in_array($role, [OrganizationRole::Owner, OrganizationRole::PropertyManager], true)], OrganizationRole::cases()));

test('correction requires current property intent confirmation and a reason', function (string $missing) {
    $tenant = twoTenants()->a;
    [, $old, $new] = correctionDocuments($tenant);
    $form = ['_property' => $tenant->property->id, 'replacement_id' => $new->id, 'reason' => 'Wrong particulars', 'confirmed' => true];
    if ($missing === 'intent') {
        $form['_property'] = $tenant->otherProperty->id;
    } else {
        unset($form[$missing]);
    }
    $response = $this->actingAs($tenant->owner)->post(route('issued-documents.corrections.store', $old), $form);
    $missing === 'intent' ? $response->assertNotFound() : $response->assertSessionHasErrors($missing);
    expect(DB::table('document_corrections')->count())->toBe(0)
        ->and(DB::table('outbox_messages')->where('event', 'errorSave')->count())->toBe(0);
    Http::assertNothingSent();
})->with(['intent', 'confirmed', 'reason']);

test('a timeout holds ERROR_SAVE and recovery cannot blindly retry or rebuild it', function () {
    $tenant = twoTenants()->a;
    [, $old, $new] = correctionDocuments($tenant);
    Http::fake(['https://vfms-live.example/vfms/error-management/new' => Http::failedConnection()]);
    submitCorrection($this, $tenant, $old, $new)->assertRedirect();
    inTenant($tenant->property, function () use ($tenant) {
        $message = OutboxMessage::query()->where('event', 'errorSave')->sole();
        app(DeliveryEngine::class)->deliver($message->id);
        expect($message->fresh()->status)->toBe('unknown');
        expect(fn () => app(OutboxRecovery::class)->retry($tenant->owner, $tenant->property->id, $message->id, 'Please retry'))->toThrow(RuntimeException::class, 'Unknown delivery requires reconciliation');
        app(DeliveryEngine::class)->deliver($message->id);
        expect($message->fresh()->attempt_count)->toBe(1);
    });
});

test('ERROR_SAVE respects its ordered predecessor and the restore gate with original fenced evidence', function (string $guard) {
    $tenant = twoTenants()->a;
    [$stay, $old, $new] = correctionDocuments($tenant);
    submitCorrection($this, $tenant, $old, $new)->assertRedirect();
    Http::fake(['https://vfms-live.example/vfms/error-management/new' => Http::response(['status' => 200])]);
    inTenant($tenant->property, function () use ($tenant, $guard) {
        $message = OutboxMessage::query()->where('event', 'errorSave')->sole();
        if ($guard === 'predecessor') {
            OutboxMessage::query()->where('sequence', 2)->update(['status' => 'unknown']);
        } else {
            app(RestoreGate::class)->close($tenant->property, 'Restore test', $tenant->owner);
        }
        app(DeliveryEngine::class)->deliver($message->id);
        expect($message->fresh()->status)->toBe($guard === 'predecessor' ? 'blocked' : 'pending')
            ->and($message->fresh()->attempt_count)->toBe(0);
    });
    Http::assertNothingSent();
})->with(['predecessor', 'restore']);

test('the original and replacement cannot be reused or form a correction cycle', function (string $attack) {
    $tenant = twoTenants()->a;
    [, $old, $new] = correctionDocuments($tenant);
    submitCorrection($this, $tenant, $old, $new)->assertRedirect();
    if ($attack === 'reverse cycle') {
        submitCorrection($this, $tenant, $new, $old)->assertSessionHasErrors('correction');
    } else {
        $third = inTenant($tenant->property, fn () => IssuedDocument::query()->create([
            ...$old->getAttributes(), 'number' => 'THIRD-232',
        ]));
        submitCorrection($this, $tenant, $attack === 'duplicate original' ? $old : $third, $new)->assertSessionHasErrors('correction');
    }
    expect(DB::table('document_corrections')->count())->toBe(1)
        ->and(DB::table('outbox_messages')->where('event', 'errorSave')->count())->toBe(1);
    Http::assertNothingSent();
})->with(['reverse cycle', 'duplicate original', 'reused replacement']);

test('a refused ERROR_SAVE has no filing recovery link and cannot be rebuilt', function () {
    $tenant = twoTenants()->a;
    [$stay, $old, $new] = correctionDocuments($tenant);
    Http::fake(['https://vfms-live.example/vfms/error-management/new' => Http::response(['status' => 5000])]);
    submitCorrection($this, $tenant, $old, $new)->assertRedirect();
    inTenant($tenant->property, function () use ($stay) {
        $message = OutboxMessage::query()->where('event', 'errorSave')->sole();
        app(DeliveryEngine::class)->deliver($message->id);
        expect(VfmsRefusal::of($stay, true))->toBeNull();
        expect(collect(VfmsFilings::of($stay, true))->pluck('id')->all())->not->toContain($message->id);
        expect(fn () => app(VfmsAdapter::class)->correct($message->fresh(), []))
            ->toThrow(RuntimeException::class, 'Issued-document corrections require reconciliation');
    });
});

test('correction locks its property stay and accepted document records within the submission transaction', function () {
    $tenant = twoTenants()->a;
    [, $old, $new] = correctionDocuments($tenant);
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        if (str_starts_with(strtolower($query->sql), 'select')) {
            $queries[] = [$query->sql, DB::transactionLevel()];
        }
    });
    submitCorrection($this, $tenant, $old, $new)->assertSessionDoesntHaveErrors();
    foreach (['properties', 'reservations', 'issued_documents', 'outbox_messages', 'outbox_attempts'] as $table) {
        expect(collect($queries)->contains(fn (array $query) => str_contains($query[0], $table) && $query[1] >= 2
            && (DB::getDriverName() === 'sqlite' || str_contains(strtolower($query[0]), 'for update'))))->toBeTrue('Submission must lock '.$table.' inside its transaction.');
    }
});

test('submission refuses a closed restore gate without recording a correction', function () {
    $tenant = twoTenants()->a;
    [, $old, $new] = correctionDocuments($tenant);
    inTenant($tenant->property, fn () => app(RestoreGate::class)->close($tenant->property, 'Restored backup', $tenant->owner));
    submitCorrection($this, $tenant, $old, $new)->assertSessionHasErrors('correction');
    expect(DB::table('document_corrections')->count())->toBe(0)
        ->and(DB::table('outbox_messages')->where('event', 'errorSave')->count())->toBe(0);
    Http::assertNothingSent();
});
