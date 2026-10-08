<?php

use App\Support\Outbox\DeliveryAdapter;
use App\Support\Outbox\DeliveryAdapters;
use App\Support\Outbox\DeliveryEngine;
use App\Support\Outbox\DeliveryPolicy;
use App\Support\Outbox\DeliveryResult;
use App\Support\Outbox\Outbox;
use App\Support\Outbox\OutboxMessage;
use App\Support\Outbox\OutboxSweeper;
use App\Support\Outbox\RestoreGate;
use App\Support\Outbox\RestoreIdentityRecovery;
use App\Support\Outbox\RestoreReconciler;
use App\Support\Outbox\RestoreReconciliationAdapter;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\ReconcilingOutboxAdapter;
use Tests\Fakes\ReplayOnlyOutboxAdapter;

beforeEach(function () {
    config(['queue.default' => 'database']);
});

test('closing gate fences an in-flight sending result as unknown', function () {
    $tenant = twoTenants();
    $actor = $tenant->a->owner;
    $adapter = new ReconcilingOutboxAdapter;
    app(DeliveryAdapters::class)->register('gate-fence', $adapter);
    $message = inTenant($tenant->a->property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'gate-fence', (object) ['type' => 'reservation', 'id' => 'gate-fence-stream'], 'changed', ['version' => 1], 'gate-fence-key',
    )));
    $message->status = 'sending';
    $message->attempt_count = 1;
    $message->claim_token = str_repeat('f', 64);
    $message->claim_expires_at = now()->addMinutes(2);
    $message->save();
    $attempt = inTenant($tenant->a->property, fn () => $message->attempts()->create([
        'organization_id' => $message->organization_id,
        'property_id' => $message->property_id,
        'attempt_number' => 1,
        'outcome' => 'sending',
        'request_body' => '{"version":1}',
    ]));

    expect(Artisan::call('outbox:gate', ['--actor' => $actor->id, '--property' => $tenant->a->property->id, '--reason' => 'Stop active delivery before restore']))->toBe(0);

    expect($message->fresh()->status)->toBe('unknown')
        ->and($message->fresh()->claim_token)->toBeNull()
        ->and($attempt->fresh()->outcome)->toBe('unknown')
        ->and(app(RestoreGate::class)->isClosed($tenant->a->property->id))->toBeTrue();
});

test('persistent gate blocks delivery engine and sweeper after process restart', function () {
    $tenant = twoTenants();
    $actor = $tenant->a->owner;
    $adapter = new class implements DeliveryAdapter
    {
        public int $sends = 0;

        public function policy(): DeliveryPolicy
        {
            return new DeliveryPolicy;
        }

        public function send(array $messages): array
        {
            $this->sends++;

            return array_map(static fn (OutboxMessage $message): DeliveryResult => new DeliveryResult('accepted'), $messages);
        }
    };
    app(DeliveryAdapters::class)->register('restart-gated', $adapter);
    $message = inTenant($tenant->a->property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'restart-gated', (object) ['type' => 'reservation', 'id' => 'restart-stream'], 'changed', ['version' => 1], 'restart-key',
    )));
    Artisan::call('outbox:gate', ['--actor' => $actor->id, '--property' => $tenant->a->property->id, '--reason' => 'Process restart after restore']);
    app()->forgetInstance(RestoreGate::class);
    inTenant($tenant->a->property, fn () => app(DeliveryEngine::class)->deliver($message->id));

    expect(DB::table('outbox_messages')->where('id', $message->id)->value('status'))->toBe('pending')
        ->and($adapter->sends)->toBe(0);
    expect(app(OutboxSweeper::class)->sweep())->toBe(0)
        ->and($adapter->sends)->toBe(0);
});

test('restore gate command persistently blocks integration writes and delivery', function () {
    $tenant = twoTenants();
    $actor = $tenant->a->owner;

    expect(Artisan::call('outbox:gate', [
        '--actor' => $actor->id,
        '--property' => $tenant->a->property->id,
        '--reason' => 'Restored from an older backup',
    ]))->toBe(0);

    expect(fn () => inTenant($tenant->a->property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'test', (object) ['type' => 'reservation', 'id' => 'reservation-1'], 'changed', ['version' => 1], 'key-1',
    ))))->toThrow(LogicException::class, 'restore gate');

    expect(app('db')->table('outbox_restore_gates')->where('property_id', $tenant->a->property->id)->value('is_closed'))->toBe(1);
    expect(Queue::size('default'))->toBe(0);
});

test('reconciliation-capable adapter resolves accepted work and identity evidence is required before resume', function () {
    $tenant = twoTenants();
    $actor = $tenant->a->owner;
    $adapter = new ReconcilingOutboxAdapter;
    app(DeliveryAdapters::class)->register('vfms-fake', $adapter);
    $message = inTenant($tenant->a->property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'vfms-fake', (object) ['type' => 'stay', 'id' => 'stay-1'], 'changed', ['version' => 1], 'key-1',
    )));
    $message->status = 'unknown';
    $message->save();
    Artisan::call('outbox:gate', ['--actor' => $actor->id, '--property' => $tenant->a->property->id, '--reason' => 'Restore exercise']);
    $adapter->reports[$message->id] = ['outcome' => 'accepted', 'evidence' => 'provider operation 872', 'references' => ['referenceNumber' => '872']];

    $report = app(RestoreReconciler::class)->reconcile($actor, $tenant->a->property, 'Checked provider history');

    expect($report)->toBe(['reconciled' => 1, 'unresolved' => 0])
        ->and($message->fresh()->status)->toBe('delivered')
        ->and(DB::table('outbox_restore_evidence')->where('message_id', $message->id)->value('evidence_hash'))->toBe(hash('sha256', 'provider operation 872'));
    expect(Artisan::call('outbox:resume', ['--actor' => $actor->id, '--property' => $tenant->a->property->id, '--reason' => 'Delivery only completed']))->toBe(1);
    $adapter->identityFloors = [[
        'subject_type' => 'stay',
        'subject_id' => 'stay-1',
        'highest_sequence' => 1,
        'evidence' => 'provider high-water mark 1',
    ]];
    Tenancy::within(TenantContext::forProperty($tenant->a->property), fn () => app(RestoreIdentityRecovery::class)->recover($tenant->a->property, $actor, 'Verified fake identity boundary'));
    expect(Artisan::call('outbox:resume', ['--actor' => $actor->id, '--property' => $tenant->a->property->id, '--reason' => 'Both checks completed']))->toBe(0);
    expect(app(RestoreGate::class)->isClosed($tenant->a->property->id))->toBeFalse();
});

test('reconciliation preserves unknown rejected exhausted and blocked outcomes on ordered and latest-only streams', function () {
    $tenant = twoTenants();
    $actor = $tenant->a->owner;
    $orderedAdapter = new ReconcilingOutboxAdapter;
    $policy = new DeliveryPolicy(ordering: 'latest-only', coalesceKey: fn (array $payload): string => (string) $payload['key']);
    $latestAdapter = new ReconcilingOutboxAdapter($policy);
    app(DeliveryAdapters::class)->register('ordered-restore', $orderedAdapter);
    app(DeliveryAdapters::class)->register('latest-restore', $latestAdapter);
    $messages = inTenant($tenant->a->property, fn () => DB::transaction(function () {
        $outbox = app(Outbox::class);
        $orderedSubject = (object) ['type' => 'reservation', 'id' => 'ordered-stream'];
        $latestSubject = (object) ['type' => 'channel-state', 'id' => 'latest-stream'];

        return [
            $outbox->record('ordered-restore', $orderedSubject, 'changed', ['version' => 1], 'ordered-1'),
            $outbox->record('ordered-restore', $orderedSubject, 'changed', ['version' => 2], 'ordered-2'),
            $outbox->record('latest-restore', $latestSubject, 'changed', ['version' => 1, 'key' => 'daily-rate'], 'latest-1'),
            $outbox->record('latest-restore', $latestSubject, 'changed', ['version' => 2, 'key' => 'daily-rate'], 'latest-2'),
        ];
    }));
    foreach ($messages as $index => $message) {
        $message->status = ['unknown', 'blocked', 'rejected', 'exhausted'][$index];
        $message->save();
        $adapter = $index < 2 ? $orderedAdapter : $latestAdapter;
        $adapter->reports[$message->id] = ['outcome' => 'unresolved', 'evidence' => 'No safe outcome established'];
    }
    Artisan::call('outbox:gate', ['--actor' => $actor->id, '--property' => $tenant->a->property->id, '--reason' => 'Restore exercise']);

    $report = app(RestoreReconciler::class)->reconcile($actor, $tenant->a->property, 'Provider history remains uncertain');

    expect($report['unresolved'])->toBe(4);
    foreach ($messages as $index => $message) {
        expect($message->fresh()->status)->toBe(['unknown', 'blocked', 'rejected', 'exhausted'][$index]);
    }
    expect(app(RestoreGate::class)->isClosed($tenant->a->property->id))->toBeTrue();
});

test('a provider cannot report a blocked ordered successor accepted', function () {
    $tenant = twoTenants();
    $actor = $tenant->a->owner;
    $adapter = new ReconcilingOutboxAdapter;
    app(DeliveryAdapters::class)->register('ordered-restore', $adapter);
    $message = inTenant($tenant->a->property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'ordered-restore', (object) ['type' => 'reservation', 'id' => 'blocked-stream'], 'changed', ['version' => 2], 'blocked-key',
    )));
    $message->status = 'blocked';
    $message->save();
    $adapter->reports[$message->id] = ['outcome' => 'accepted', 'evidence' => 'Impossible blocked acceptance'];
    Artisan::call('outbox:gate', ['--actor' => $actor->id, '--property' => $tenant->a->property->id, '--reason' => 'Restore exercise']);

    expect(fn () => app(RestoreReconciler::class)->reconcile($actor, $tenant->a->property, 'Check ordering'))
        ->toThrow(LogicException::class, 'cannot rewrite');
    expect($message->fresh()->status)->toBe('blocked')
        ->and(app(RestoreGate::class)->isClosed($tenant->a->property->id))->toBeTrue();
});

test('identity recovery refuses to mark safe when a restored stream has no provider high-water floor', function () {
    $tenant = twoTenants();
    $actor = $tenant->a->owner;
    $adapter = new ReconcilingOutboxAdapter;
    app(DeliveryAdapters::class)->register('vfms-restore', $adapter);
    inTenant($tenant->a->property, fn () => DB::transaction(function () {
        app(Outbox::class)->record('vfms-restore', (object) ['type' => 'stay', 'id' => 'covered'], 'changed', ['version' => 1], 'covered-key');
        app(Outbox::class)->record('vfms-restore', (object) ['type' => 'stay', 'id' => 'omitted'], 'changed', ['version' => 1], 'omitted-key');
    }));
    Artisan::call('outbox:gate', ['--actor' => $actor->id, '--property' => $tenant->a->property->id, '--reason' => 'Restore exercise']);
    expect(app(RestoreReconciler::class)->reconcile($actor, $tenant->a->property, 'No unresolved local messages')['unresolved'])->toBe(0);
    $adapter->identityFloors = [['subject_type' => 'stay', 'subject_id' => 'covered', 'highest_sequence' => 1, 'evidence' => 'Only one provider stream reported']];

    expect(fn () => Tenancy::within(TenantContext::forProperty($tenant->a->property), fn () => app(RestoreIdentityRecovery::class)->recover($tenant->a->property, $actor, 'Incomplete stream identity report')))
        ->toThrow(LogicException::class, 'omitted a restored stream');

    expect(DB::table('outbox_restore_gates')->where('property_id', $tenant->a->property->id)->value('identity_safe_at'))->toBeNull();
    expect(Artisan::call('outbox:resume', ['--actor' => $actor->id, '--property' => $tenant->a->property->id, '--reason' => 'Incomplete floor coverage']))->toBe(1)
        ->and(DB::table('outbox_restore_gates')->where('property_id', $tenant->a->property->id)->value('is_closed'))->toBe(1);
});

test('identity recovery requires complete coverage from each registered integration', function () {
    $tenant = twoTenants();
    $actor = $tenant->a->owner;
    $vfms = new ReconcilingOutboxAdapter;
    $zan = new ReconcilingOutboxAdapter;
    $zan->completeIdentityCoverage = false;
    app(DeliveryAdapters::class)->register('vfms-restore', $vfms);
    app(DeliveryAdapters::class)->register('zan-restore', $zan);
    Artisan::call('outbox:gate', ['--actor' => $actor->id, '--property' => $tenant->a->property->id, '--reason' => 'Restore exercise']);
    expect(app(RestoreReconciler::class)->reconcile($actor, $tenant->a->property, 'No outbox records'))->toBe(['reconciled' => 0, 'unresolved' => 0]);
    $vfms->identityFloors = [['subject_type' => 'stay', 'subject_id' => 'a', 'highest_sequence' => 0, 'evidence' => 'VFMS complete ledger']];
    $zan->identityFloors = [['subject_type' => 'reservation', 'subject_id' => 'b', 'highest_sequence' => 0, 'evidence' => 'ZAN ledger']];

    expect(fn () => Tenancy::within(TenantContext::forProperty($tenant->a->property), fn () => app(RestoreIdentityRecovery::class)->recover($tenant->a->property, $actor, 'Recover all identity contracts')))
        ->toThrow(LogicException::class, 'complete verified identity-boundary contract');
    expect(DB::table('outbox_identity_floors')->where('property_id', $tenant->a->property->id)->count())->toBe(0);
});

test('identity recovery rejects a provider that cannot attest complete scope coverage', function () {
    $tenant = twoTenants();
    $actor = $tenant->a->owner;
    $adapter = new ReconcilingOutboxAdapter;
    $adapter->completeIdentityCoverage = false;
    app(DeliveryAdapters::class)->register('incomplete-fake', $adapter);
    Artisan::call('outbox:gate', ['--actor' => $actor->id, '--property' => $tenant->a->property->id, '--reason' => 'Restore exercise']);
    expect(app(RestoreReconciler::class)->reconcile($actor, $tenant->a->property, 'No local messages to reconcile'))->toBe(['reconciled' => 0, 'unresolved' => 0]);

    expect(fn () => Tenancy::within(TenantContext::forProperty($tenant->a->property), fn () => app(RestoreIdentityRecovery::class)->recover($tenant->a->property, $actor, 'Incomplete provider history')))
        ->toThrow(LogicException::class, 'complete verified identity-boundary contract');
    expect(app(RestoreGate::class)->isClosed($tenant->a->property->id))->toBeTrue();
});

test('older backup missing externally accepted history is reported and identity boundary blocks allocation until verified', function () {
    $tenant = twoTenants();
    $actor = $tenant->a->owner;
    $adapter = new ReconcilingOutboxAdapter;
    app(DeliveryAdapters::class)->register('vfms-restore', $adapter);
    $restored = inTenant($tenant->a->property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'vfms-restore', (object) ['type' => 'stay', 'id' => 'stay-restore'], 'changed', ['sequence' => 4], 'restored-key',
    )));
    $restored->status = 'unknown';
    $restored->save();
    Artisan::call('outbox:gate', ['--actor' => $actor->id, '--property' => $tenant->a->property->id, '--reason' => 'Restore older backup']);
    $adapter->reports[$restored->id] = ['outcome' => 'accepted', 'evidence' => 'provider accepted pre-restore sequence 5, absent locally'];

    $report = app(RestoreReconciler::class)->reconcile($actor, $tenant->a->property, 'Reconciled provider operation history');

    expect($report)->toBe(['reconciled' => 1, 'unresolved' => 0])
        ->and($restored->fresh()->status)->toBe('delivered')
        ->and(DB::table('outbox_restore_evidence')->where('property_id', $tenant->a->property->id)->value('evidence_hash'))
        ->toBe(hash('sha256', 'provider accepted pre-restore sequence 5, absent locally'));
    expect(fn () => inTenant($tenant->a->property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'vfms-restore', (object) ['type' => 'stay', 'id' => 'stay-restore'], 'changed', ['sequence' => 6], 'new-key',
    ))))->toThrow(LogicException::class, 'restore gate');
    expect(Artisan::call('outbox:resume', ['--actor' => $actor->id, '--property' => $tenant->a->property->id, '--reason' => 'Identity boundary not yet verified']))->toBe(1);
    $adapter->identityFloors = [[
        'subject_type' => 'stay',
        'subject_id' => 'stay-restore',
        'highest_sequence' => 5,
        'evidence' => 'provider confirms sequence 5 accepted, absent from restored database',
    ]];
    Tenancy::within(TenantContext::forProperty($tenant->a->property), fn () => app(RestoreIdentityRecovery::class)->recover($tenant->a->property, $actor, 'Recovered missing provider sequence history'));
    expect(Artisan::call('outbox:resume', ['--actor' => $actor->id, '--property' => $tenant->a->property->id, '--reason' => 'Identity boundary verified']))->toBe(0);
    $newMessage = inTenant($tenant->a->property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'vfms-restore', (object) ['type' => 'stay', 'id' => 'stay-restore'], 'changed', ['sequence' => 6], 'new-key',
    )));
    expect($newMessage->sequence)->toBe(6);
});

// The system-facing safety checks run through public command and delivery seams.

function restoreReplayMessage($property, string $integration = 'replay-fake'): OutboxMessage
{
    $message = inTenant($property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        $integration, (object) ['type' => 'reservation', 'id' => 'old-operation'], 'changed', ['version' => 1], 'restore-replay-key',
    )));
    $message->status = 'unknown';
    $message->save();

    return $message;
}

test('missing provider contract retains closed gate and failed reconciliation keeps evidence history atomic', function () {
    $tenant = twoTenants();
    $actor = $tenant->a->owner;
    $adapter = new ReconcilingOutboxAdapter;
    app(DeliveryAdapters::class)->register('broken-fake', $adapter);
    $work = inTenant($tenant->a->property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'broken-fake', (object) ['type' => 'reservation', 'id' => 'reservation-2'], 'changed', ['version' => 1], 'key-2',
    )));
    $work->status = 'unknown';
    $work->save();
    Artisan::call('outbox:gate', ['--actor' => $actor->id, '--property' => $tenant->a->property->id, '--reason' => 'Restore exercise']);
    $adapter->fail = true;

    expect(fn () => app(RestoreReconciler::class)->reconcile($actor, $tenant->a->property, 'Interrupted provider query'))
        ->toThrow(RuntimeException::class, 'interrupted');
    expect($work->fresh()->status)->toBe('unknown')
        ->and(DB::table('outbox_restore_evidence')->where('property_id', $tenant->a->property->id)->count())->toBe(0)
        ->and(app(RestoreGate::class)->isClosed($tenant->a->property->id))->toBeTrue();
    inTenant($tenant->a->property, fn () => app(DeliveryEngine::class)->deliver($work->id));
    expect($work->fresh()->status)->toBe('unknown');
});

test('replay-only adapters require a verified contract and do not imply delivery acceptance', function () {
    $tenant = twoTenants();
    $actor = $tenant->a->owner;
    $adapter = new ReplayOnlyOutboxAdapter;
    app(DeliveryAdapters::class)->register('replay-fake', $adapter);
    $work = inTenant($tenant->a->property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'replay-fake', (object) ['type' => 'reservation', 'id' => 'reservation-3'], 'changed', ['version' => 1], 'key-3',
    )));
    $work->status = 'unknown';
    $work->save();
    Artisan::call('outbox:gate', ['--actor' => $actor->id, '--property' => $tenant->a->property->id, '--reason' => 'Restore exercise']);

    $report = app(RestoreReconciler::class)->reconcile($actor, $tenant->a->property, 'Verified fake replay contract');

    expect($report)->toBe(['reconciled' => 1, 'unresolved' => 0])
        ->and($work->fresh()->status)->toBe('pending')
        ->and(app(RestoreGate::class)->isClosed($tenant->a->property->id))->toBeTrue();
    inTenant($tenant->a->property, fn () => app(DeliveryEngine::class)->deliver($work->id));
    expect($work->fresh()->status)->toBe('pending');
});

test('replay-only provider without identity contract cannot reopen after safe replay', function () {
    $tenant = twoTenants();
    $actor = $tenant->a->owner;
    $adapter = new ReplayOnlyOutboxAdapter;
    app(DeliveryAdapters::class)->register('replay-only', $adapter);
    $work = inTenant($tenant->a->property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'replay-only', (object) ['type' => 'reservation', 'id' => 'replay-only-stream'], 'changed', ['version' => 1], 'replay-key',
    )));
    $work->status = 'unknown';
    $work->save();
    Artisan::call('outbox:gate', ['--actor' => $actor->id, '--property' => $tenant->a->property->id, '--reason' => 'Restore exercise']);
    expect(app(RestoreReconciler::class)->reconcile($actor, $tenant->a->property, 'Safe unchanged replay contract')['unresolved'])->toBe(0);
    expect(Artisan::call('outbox:resume', ['--actor' => $actor->id, '--property' => $tenant->a->property->id, '--reason' => 'Identity coverage absent']))->toBe(1)
        ->and($work->fresh()->status)->toBe('pending')
        ->and(app(RestoreGate::class)->isClosed($tenant->a->property->id))->toBeTrue();
});

test('optional reconciliation does not make a replay-only provider safe', function () {
    $tenant = twoTenants();
    $actor = $tenant->a->owner;
    $adapter = new class implements DeliveryAdapter, RestoreReconciliationAdapter
    {
        public function policy(): DeliveryPolicy
        {
            return new DeliveryPolicy;
        }

        public function send(array $messages): array
        {
            return [];
        }

        public function reconcile(array $messages): array
        {
            return [];
        }
    };
    app(DeliveryAdapters::class)->register('optional-only', $adapter);
    inTenant($tenant->a->property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'optional-only', (object) ['type' => 'reservation', 'id' => 'reservation-4'], 'changed', ['version' => 1], 'key-4',
    )));
    Artisan::call('outbox:gate', ['--actor' => $actor->id, '--property' => $tenant->a->property->id, '--reason' => 'Restore exercise']);

    expect(fn () => app(RestoreReconciler::class)->reconcile($actor, $tenant->a->property, 'Optional capability is not safety'))
        ->toThrow(LogicException::class, 'ambiguous');
    expect(app(RestoreGate::class)->isClosed($tenant->a->property->id))->toBeTrue();
});

// The system-facing safety checks run through public command and delivery seams.
