<?php

namespace App\Support\Outbox;

use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class RestoreReconciler
{
    /** @return array{reconciled: int, unresolved: int} */
    public function reconcile(User $actor, Property $property, string $reason): array
    {
        if (trim($reason) === '') {
            throw new LogicException('A reason is required.');
        }

        $context = TenantContext::forProperty($property);
        $messages = Tenancy::within($context, function () use ($actor, $property): array {
            if (! Access::allows($actor, OrganizationPermission::OutboxRecover, $property)) {
                throw new LogicException('Outbox recovery is not authorized.');
            }
            if (! DB::table('outbox_restore_gates')->where('property_id', $property->id)->where('is_closed', true)->exists()) {
                throw new LogicException('Close the restore gate before reconciliation.');
            }

            return OutboxMessage::query()->where('property_id', $property->id)->whereNotIn('status', ['delivered', 'superseded'])->orderBy('created_at')->get()->all();
        });

        $reports = [];
        foreach (collect($messages)->groupBy('integration') as $integration => $group) {
            $adapter = app(DeliveryAdapters::class)->for($integration);
            if (! $adapter instanceof RestoreReconciliationAdapter) {
                if (! $adapter instanceof VerifiedSafeReplayAdapter || trim($adapter->safeReplayContract()) === '') {
                    throw new LogicException("Integration [{$integration}] has no verified restore recovery contract.");
                }
                foreach ($group as $message) {
                    $reports[$message->id] = [
                        'integration' => $integration,
                        'outcome' => in_array($message->status, ['rejected', 'exhausted', 'blocked'], true) ? 'unresolved' : 'not_found',
                        'evidence' => 'verified-safe-replay:'.$adapter->safeReplayContract(),
                    ];
                }

                continue;
            }

            $adapterReports = $adapter->reconcile($group->all());
            $expectedIds = array_map(static fn (OutboxMessage $message): string => $message->id, $group->all());
            if (array_diff($expectedIds, array_keys($adapterReports)) !== [] || array_diff(array_keys($adapterReports), $expectedIds) !== []) {
                throw new LogicException("Integration [{$integration}] returned ambiguous reconciliation results.");
            }
            foreach ($adapterReports as $messageId => $report) {
                if (! in_array($report['outcome'] ?? null, ['accepted', 'not_found', 'unresolved'], true) || trim((string) ($report['evidence'] ?? '')) === '') {
                    throw new LogicException("Integration [{$integration}] returned invalid reconciliation evidence.");
                }
                $reports[$messageId] = ['integration' => $integration] + $report;
            }
        }

        return Tenancy::within($context, fn (): array => DB::transaction(function () use ($actor, $property, $reason, $messages, $reports): array {
            Property::query()->whereKey($property->id)->lockForUpdate()->firstOrFail();
            $gate = DB::table('outbox_restore_gates')->where('property_id', $property->id)->lockForUpdate()->first();
            if ($gate === null || ! $gate->is_closed) {
                throw new LogicException('The restore gate changed while reconciliation was in progress.');
            }
            $current = OutboxMessage::query()->whereIn('id', array_map(static fn (OutboxMessage $message): string => $message->id, $messages))->lockForUpdate()->get()->keyBy('id');
            if ($current->count() !== count($messages)) {
                throw new LogicException('Outbox history changed while reconciliation was in progress.');
            }
            foreach ($messages as $snapshot) {
                if ($current[$snapshot->id]->status !== $snapshot->status) {
                    throw new LogicException('Outbox status changed while reconciliation was in progress.');
                }
            }

            foreach ($messages as $snapshot) {
                $message = $current[$snapshot->id];
                $report = $reports[$message->id];
                $adapter = app(DeliveryAdapters::class)->for($report['integration']);
                if ($report['outcome'] === 'accepted') {
                    if (! in_array($message->status, ['unknown', 'exhausted_unknown', 'sending', 'retryable', 'pending'], true)) {
                        throw new LogicException('Reconciliation cannot rewrite a confirmed rejected or exhausted operation as accepted.');
                    }
                    $message->status = 'delivered';
                    $message->delivered_at = now();
                    $message->setAttribute('provider_references', $report['references'] ?? null);
                    $message->setAttribute('claim_token', null);
                    $message->setAttribute('claim_expires_at', null);
                    $message->setAttribute('dispatch_claim_token', null);
                    $message->setAttribute('dispatch_claim_expires_at', null);
                    $message->setAttribute('next_attempt_at', null);
                    $message->save();
                } elseif ($report['outcome'] === 'not_found' && $adapter instanceof VerifiedSafeReplayAdapter
                    && in_array($message->status, ['sending', 'unknown', 'exhausted_unknown'], true)) {
                    $message->status = 'pending';
                    $message->setAttribute('next_attempt_at', null);
                    $message->setAttribute('claim_token', null);
                    $message->setAttribute('claim_expires_at', null);
                    $message->setAttribute('dispatch_claim_token', null);
                    $message->setAttribute('dispatch_claim_expires_at', null);
                    $message->save();
                }
                DB::table('outbox_restore_evidence')->insert([
                    'id' => (string) Str::ulid(),
                    'organization_id' => $message->organization_id,
                    'property_id' => $message->property_id,
                    'integration' => $report['integration'],
                    'message_id' => $message->id,
                    'outcome' => $report['outcome'],
                    'evidence_hash' => hash('sha256', $report['evidence']),
                    'provider_references' => isset($report['references']) ? json_encode($report['references'], JSON_THROW_ON_ERROR) : null,
                    'actor_id' => $actor->id,
                    'reason' => $reason,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                Audit::record($actor, 'outbox_restore_reconciled', $message, 'Reconciled outbox operation after restore', [
                    'reason' => $reason,
                    'outcome' => $report['outcome'],
                    'evidence_hash' => hash('sha256', $report['evidence']),
                ]);
            }

            $unresolved = DB::table('outbox_messages as messages')
                ->where('messages.property_id', $property->id)
                ->whereIn('messages.status', ['unknown', 'exhausted_unknown', 'rejected', 'exhausted', 'blocked', 'sending'])
                ->count();
            if ($unresolved > 0) {
                return ['reconciled' => count($messages), 'unresolved' => $unresolved];
            }
            app(RestoreGate::class)->recordDeliveryReconciled($property, $actor, $reason);

            return ['reconciled' => count($messages), 'unresolved' => 0];
        }));
    }
}
