<?php

use App\Support\Outbox\DeliveryAdapter;
use App\Support\Outbox\DeliveryAdapters;
use App\Support\Outbox\DeliveryEngine;
use App\Support\Outbox\DeliveryPolicy;
use App\Support\Outbox\Outbox;
use App\Support\Outbox\OutboxMessage;
use App\Support\Outbox\RestoreGate;
use App\Support\Outbox\RestoreReconciler;
use App\Support\Outbox\RestoreReconciliationAdapter;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\ReconcilingOutboxAdapter;
use Tests\Fakes\ReplayOnlyOutboxAdapter;

beforeEach(function () {
    config(['queue.default' => 'database']);
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
    Tenancy::within(TenantContext::forProperty($tenant->a->property), fn () => app(RestoreGate::class)->recordIdentitySafe($tenant->a->property, $actor, 'Verified identity ledger boundary', 'ledger-cutoff-2026-10-01', 'verified-boundary'));
    expect(Artisan::call('outbox:resume', ['--actor' => $actor->id, '--property' => $tenant->a->property->id, '--reason' => 'Both checks completed']))->toBe(0);
    expect(app(RestoreGate::class)->isClosed($tenant->a->property->id))->toBeFalse();
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
    expect(fn () => inTenant($tenant->a->property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'vfms-restore', (object) ['type' => 'stay', 'id' => 'stay-restore'], 'changed', ['sequence' => 6], 'new-key',
    ))))->toThrow(LogicException::class, 'restore gate');
    expect(app(RestoreGate::class)->isClosed($tenant->a->property->id))->toBeTrue();
    expect(Artisan::call('outbox:resume', ['--actor' => $actor->id, '--property' => $tenant->a->property->id, '--reason' => 'Identity boundary not yet verified']))->toBe(1);
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

function restoreReplayMessage($property, string $integration = 'replay-fake'): OutboxMessage
{
    $message = inTenant($property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        $integration, (object) ['type' => 'reservation', 'id' => 'old-operation'], 'changed', ['version' => 1], 'restore-replay-key',
    )));
    $message->status = 'unknown';
    $message->save();

    return $message;
}

// The system-facing safety checks run through public command and delivery seams.
