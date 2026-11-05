<?php

use App\Domains\FiscalReceipting\Actions\RefreshCorrectionDecisions;
use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Jobs\ReadCorrectionDecisions;
use App\Domains\FiscalReceipting\Models\DocumentCorrection;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Support\Outbox\DeliveryEngine;
use App\Support\Outbox\DrainOutboxMessage;
use App\Support\Outbox\OutboxMessage;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TenantFixture;

/*
 * Section 2.13 gives no response schema. Every report below is a PROVISIONAL flat-list fake
 * (old_receipt_number/new_receipt_number/unitId/status), not observed ZRA conformance.
 */

beforeEach(function () {
    // Only the filing drain is faked: the decision read runs through the real queue and tenant handler.
    Queue::fake([DrainOutboxMessage::class]);
    Http::preventStrayRequests();
    config(['services.vfms.live_url' => 'https://vfms-live.example/vfms/api/']);
    $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam'));
    $this->reads = [];
});

/** @param  array<int, mixed>  $reads  responses for successive section 2.13 reads */
function fakeCorrectionZra(array &$reads, mixed $save = ['status' => 200]): void
{
    Http::fake(function (Request $request) use (&$reads, $save) {
        if ($request->url() === 'https://vfms-live.example/vfms/error-management/new') {
            return $save instanceof Closure ? $save() : Http::response($save);
        }
        if ($request->url() === 'https://vfms-live.example/vfms/error-management/errors') {
            $next = array_shift($reads);

            return $next instanceof Closure ? $next() : $next;
        }

        return null;
    });
}

function decisionRow(string $status, string $old = 'OLD-232', string $new = 'NEW-232', mixed $unitId = 232): array
{
    return ['old_receipt_number' => $old, 'new_receipt_number' => $new, 'unitId' => $unitId, 'status' => $status];
}

/** A submitted correction whose ERROR_SAVE was delivered (or left as the save response leaves it). */
function sentCorrection($test, TenantFixture $tenant): DocumentCorrection
{
    [, $old, $new] = correctionDocuments($tenant);
    submitCorrection($test, $tenant, $old, $new)->assertRedirect();

    return inTenant($tenant->property, function () {
        $correction = DocumentCorrection::query()->sole();
        app(DeliveryEngine::class)->deliver($correction->outbox_message_id);

        return $correction;
    });
}

function refreshDecisions($test, TenantFixture $tenant, DocumentCorrection $correction, $actor = null)
{
    return $test->actingAs($actor ?? $tenant->owner)->post(route('document-corrections.refresh', $correction), ['_property' => $tenant->property->id]);
}

function decisionOf(TenantFixture $tenant, DocumentCorrection $correction): ?string
{
    return inTenant($tenant->property, fn () => $correction->fresh()->decision());
}

test('an accepted submission awaits ZRA and a refresh records each provisional decision from an exact read', function (string $status, string $decision) {
    $tenant = twoTenants()->a;
    fakeCorrectionZra($this->reads);
    $correction = sentCorrection($this, $tenant);
    expect(decisionOf($tenant, $correction))->toBe('awaiting');
    $this->reads[] = Http::response([decisionRow('Pending', 'OTHER-OLD', 'OTHER-NEW'), decisionRow($status)]);

    refreshDecisions($this, $tenant, $correction)->assertRedirect()->assertSessionDoesntHaveErrors();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://vfms-live.example/vfms/error-management/errors'
        && $request->header('Content-type') === ['application/json']
        && $request->header('vfms-request-type') === ['ERRORS_MANAGEMENTS']
        && $request->header('vfms-token-id') === ['SECRET-TOKEN']
        && $request->body() === '{"startDate":"2026-12-10","endDate":"2026-12-10","status":"0"}');
    expect(decisionOf($tenant, $correction))->toBe($decision)
        ->and(inTenant($tenant->property, fn () => RefreshCorrectionDecisions::status($tenant->property)))
        ->toMatchArray(['refreshing' => false, 'observedAt' => now()->toIso8601String()])
        ->and(DB::table('correction_decisions')->count())->toBe(1)
        ->and(DB::table('activity_log')->where('event', 'document_correction_decision_observed')->count())->toBe(1)
        ->and(DB::table('correction_decision_reads')->value('response_body'))->not->toContain('OTHER-OLD');
    // Approval changes no ledger or issued evidence and writes nothing back to ZRA.
    expect(DB::table('postings')->count())->toBe(0)
        ->and(DB::table('outbox_messages')->where('event', 'errorSave')->count())->toBe(1);
    Http::assertSentCount(2);
})->with([['Pending', 'pending'], ['Verification', 'verification'], ['Approved', 'approved'], ['Rejected', 'rejected']]);

test('unknown malformed or failed reads stay unresolved and preserve the prior decision and observation time', function (Closure $response) {
    $tenant = twoTenants()->a;
    fakeCorrectionZra($this->reads);
    $correction = sentCorrection($this, $tenant);
    $this->reads[] = Http::response([decisionRow('Pending')]);
    refreshDecisions($this, $tenant, $correction);
    $observed = inTenant($tenant->property, fn () => RefreshCorrectionDecisions::status($tenant->property));
    $this->travel(61)->seconds();
    $this->reads[] = $response;

    refreshDecisions($this, $tenant, $correction)->assertSessionDoesntHaveErrors();

    expect(decisionOf($tenant, $correction))->toBe('pending')
        ->and(inTenant($tenant->property, fn () => RefreshCorrectionDecisions::status($tenant->property)))->toBe($observed)
        ->and(DB::table('correction_decisions')->count())->toBe(1)
        ->and(DB::table('correction_decision_reads')->orderByDesc('id')->value('outcome'))->not->toBe('observed');
})->with([
    'wrapper object' => fn () => fn () => Http::response(['data' => [decisionRow('Approved')]]),
    'paginated wrapper' => fn () => fn () => Http::response(['content' => [decisionRow('Approved')], 'totalPages' => 2]),
    'query selector as decision' => fn () => fn () => Http::response([decisionRow('0')]),
    'unknown status' => fn () => fn () => Http::response([decisionRow('Accepted')]),
    'lowercase status' => fn () => fn () => Http::response([decisionRow('approved')]),
    'missing unit' => fn () => fn () => Http::response([['old_receipt_number' => 'OLD-232', 'new_receipt_number' => 'NEW-232', 'status' => 'Approved']]),
    'string unit' => fn () => fn () => Http::response([decisionRow('Approved', unitId: '232')]),
    'incomplete row elsewhere' => fn () => fn () => Http::response([decisionRow('Approved'), ['status' => 'Rejected']]),
    'not JSON' => fn () => fn () => Http::response('<html>gateway</html>'),
    'server error' => fn () => fn () => Http::response([decisionRow('Approved')], 503),
    'refused' => fn () => fn () => Http::response(['status' => 400, 'message' => 'Invalid date'], 400),
    'timeout' => fn () => fn () => Http::failedConnection(),
]);

test('absent foreign and conflicting pairs never become a decision', function (array $rows, string $outcome) {
    $tenant = twoTenants()->a;
    fakeCorrectionZra($this->reads);
    $correction = sentCorrection($this, $tenant);
    $this->reads[] = Http::response($rows);

    refreshDecisions($this, $tenant, $correction);

    expect(decisionOf($tenant, $correction))->toBe('awaiting')
        ->and(DB::table('correction_decisions')->count())->toBe(0)
        ->and(DB::table('correction_decision_reads')->value('outcome'))->toBe($outcome);
})->with([
    'absent is not rejection' => [[], 'observed'],
    'foreign unit' => [[decisionRow('Rejected', unitId: 999)], 'observed'],
    'old number only' => [[decisionRow('Approved', new: 'OTHER-NEW')], 'unresolved'],
    'new number only' => [[decisionRow('Approved', old: 'OTHER-OLD')], 'unresolved'],
    'reversed pair' => [[decisionRow('Approved', 'NEW-232', 'OLD-232')], 'observed'],
    'ambiguous duplicates' => [[decisionRow('Approved'), decisionRow('Rejected')], 'unresolved'],
]);

test('identical refreshes are idempotent and stale or contradictory changes need reconciliation', function (string $first, string $second, string $decision, string $outcome) {
    $tenant = twoTenants()->a;
    fakeCorrectionZra($this->reads);
    $correction = sentCorrection($this, $tenant);
    $this->reads[] = Http::response([decisionRow($first)]);
    refreshDecisions($this, $tenant, $correction);
    $this->travel(61)->seconds();
    $this->reads[] = Http::response([decisionRow($second)]);

    refreshDecisions($this, $tenant, $correction);

    expect(decisionOf($tenant, $correction))->toBe($decision)
        ->and(DB::table('correction_decisions')->count())->toBe($first === $second || $outcome === 'unresolved' ? 1 : 2)
        ->and(DB::table('correction_decision_reads')->orderByDesc('id')->value('outcome'))->toBe($outcome);
})->with([
    'repeat' => ['Pending', 'Pending', 'pending', 'observed'],
    'progress' => ['Pending', 'Approved', 'approved', 'observed'],
    'stale regression' => ['Verification', 'Pending', 'verification', 'unresolved'],
    'contradictory terminal' => ['Approved', 'Rejected', 'approved', 'unresolved'],
    'reopened terminal' => ['Rejected', 'Verification', 'rejected', 'unresolved'],
]);

test('the read does not wait behind an uncertain ERROR_SAVE and only exact evidence reconciles it', function (bool $present) {
    $tenant = twoTenants()->a;
    fakeCorrectionZra($this->reads, fn () => Http::failedConnection());
    $correction = sentCorrection($this, $tenant);
    $message = fn () => inTenant($tenant->property, fn () => OutboxMessage::query()->findOrFail($correction->outbox_message_id));
    expect($message()->status)->toBe('unknown')->and(decisionOf($tenant, $correction))->toBeNull();
    $this->reads[] = Http::response($present ? [decisionRow('Pending')] : [decisionRow('Pending', new: 'OTHER-NEW')]);

    refreshDecisions($this, $tenant, $correction)->assertSessionDoesntHaveErrors();

    expect($message()->status)->toBe($present ? 'delivered' : 'unknown')
        ->and($message()->attempt_count)->toBe(1)
        ->and(decisionOf($tenant, $correction))->toBe($present ? 'pending' : null);
    Http::assertSentCount(2);
})->with(['exact pair' => true, 'conflicting pair' => false]);

test('at most one new read per property per minute and none while one is in progress', function () {
    $tenant = twoTenants()->a;
    fakeCorrectionZra($this->reads);
    $correction = sentCorrection($this, $tenant);
    $this->reads = [Http::response([]), Http::response([])];
    refreshDecisions($this, $tenant, $correction)->assertSessionDoesntHaveErrors();
    refreshDecisions($this, $tenant, $correction)->assertSessionHasErrors('refresh');
    $this->travel(61)->seconds();
    refreshDecisions($this, $tenant, $correction)->assertSessionDoesntHaveErrors();
    Http::assertSentCount(3);

    Queue::fake([ReadCorrectionDecisions::class]);
    $this->travel(61)->seconds();
    refreshDecisions($this, $tenant, $correction)->assertSessionDoesntHaveErrors();
    expect(inTenant($tenant->property, fn () => RefreshCorrectionDecisions::status($tenant->property))['refreshing'])->toBeTrue();
    $this->travel(61)->seconds();
    refreshDecisions($this, $tenant, $correction)->assertSessionHasErrors(['refresh' => 'A ZRA status refresh is already in progress.']);
    Queue::assertPushed(ReadCorrectionDecisions::class, 1);
    // A read whose worker died does not block refreshes forever.
    $this->travel(10)->minutes();
    refreshDecisions($this, $tenant, $correction)->assertSessionDoesntHaveErrors();
    Queue::assertPushed(ReadCorrectionDecisions::class, 2);
});

test('a refresh window runs from the submission date to the current property date', function () {
    $tenant = twoTenants()->a;
    fakeCorrectionZra($this->reads);
    // 22:30 UTC is already the next day in Zanzibar.
    $this->travelTo(CarbonImmutable::parse('2026-12-10 22:30:00', 'UTC'));
    $correction = sentCorrection($this, $tenant);
    $this->travelTo(CarbonImmutable::parse('2026-12-13 23:59:00', 'Africa/Dar_es_Salaam'));
    $this->reads[] = Http::response([]);

    refreshDecisions($this, $tenant, $correction);

    Http::assertSent(fn (Request $request) => $request->data() === ['startDate' => '2026-12-11', 'endDate' => '2026-12-13', 'status' => '0']);
});

test('Sandbox and unsent corrections are refused before any read', function (string $change, string $message) {
    $tenant = twoTenants()->a;
    fakeCorrectionZra($this->reads, ['status' => 5000]);
    $correction = sentCorrection($this, $tenant);
    inTenant($tenant->property, fn () => match ($change) {
        'Sandbox' => VfmsCredential::for($tenant->property)->update(['mode' => VfmsMode::Sandbox]),
        'Mainland' => $tenant->property->update(['jurisdiction_profile' => 'mainland_tanzania']),
        'refused submission' => null,
    });

    refreshDecisions($this, $tenant, $correction)->assertSessionHasErrors(['refresh' => $message]);

    expect(DB::table('correction_decision_reads')->count())->toBe(0)
        ->and(DB::table('activity_log')->where('event', 'refused')->count())->toBeGreaterThan(0);
    Http::assertSentCount(1);
})->with([
    ['Sandbox', 'ZRA does not provide error correction in Sandbox.'],
    ['Mainland', 'Issued-document corrections require an active real Zanzibar property.'],
    ['refused submission', 'The correction must reach ZRA before its status can be refreshed.'],
]);

test('the worker rechecks Sandbox and never sends a queued read in test mode', function () {
    $tenant = twoTenants()->a;
    fakeCorrectionZra($this->reads);
    $correction = sentCorrection($this, $tenant);
    Queue::fake([ReadCorrectionDecisions::class]);
    refreshDecisions($this, $tenant, $correction)->assertSessionDoesntHaveErrors();
    inTenant($tenant->property, function () use ($tenant) {
        VfmsCredential::for($tenant->property)->update(['mode' => VfmsMode::Sandbox]);
        Queue::pushed(ReadCorrectionDecisions::class)->sole()->handle();
        expect(RefreshCorrectionDecisions::status($tenant->property)['refreshing'])->toBeFalse();
    });

    expect(DB::table('correction_decision_reads')->value('outcome'))->toBe('failed');
    Http::assertSentCount(1);
});

test('the decision read is tenant-aware and refuses to run without a tenant', function () {
    expect(fn () => ReadCorrectionDecisions::dispatch(1))->toThrow(MissingTenantContext::class);
});

test('refresh is limited to OutboxRecover roles in the selected property', function (string $role, bool $allowed) {
    $tenant = twoTenants()->a;
    fakeCorrectionZra($this->reads);
    $correction = sentCorrection($this, $tenant);
    $this->reads[] = Http::response([]);

    $response = refreshDecisions($this, $tenant, $correction, $tenant->member(OrganizationRole::from($role)));

    $allowed ? $response->assertRedirect()->assertSessionDoesntHaveErrors() : $response->assertForbidden();
    expect(DB::table('correction_decision_reads')->count())->toBe($allowed ? 1 : 0);
    Http::assertSentCount($allowed ? 2 : 1);
})->with(fn () => array_map(fn ($role) => [$role->value, in_array($role, [OrganizationRole::Owner, OrganizationRole::PropertyManager], true)], OrganizationRole::cases()));

test('refresh requires the correction property intent and refuses a sibling property correction', function (string $attack) {
    $tenant = twoTenants()->a;
    fakeCorrectionZra($this->reads);
    $correction = sentCorrection($this, $tenant);
    $form = ['_property' => $attack === 'stale intent' ? $tenant->otherProperty->id : $tenant->property->id];
    if ($attack === 'sibling property') {
        $this->actingAs($tenant->owner)->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->otherProperty->id]);
        $form['_property'] = $tenant->otherProperty->id;
    }

    $this->actingAs($tenant->owner)->post(route('document-corrections.refresh', $correction), $form)->assertNotFound();

    expect(DB::table('correction_decision_reads')->count())->toBe(0);
    Http::assertSentCount(1);
})->with(['stale intent', 'sibling property']);
