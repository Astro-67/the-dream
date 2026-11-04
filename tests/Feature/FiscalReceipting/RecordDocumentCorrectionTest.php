<?php

use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\IssuedDocuments;
use App\Domains\FiscalReceipting\Models\IssuedDocument;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Support\Outbox\DeliveryEngine;
use App\Support\Outbox\Outbox;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Http\Client\Request;
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
