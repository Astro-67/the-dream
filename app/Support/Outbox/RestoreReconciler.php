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

        return Tenancy::within(TenantContext::forProperty($property), fn (): array => DB::transaction(function () use ($actor, $property, $reason): array {
            if (! Access::allows($actor, OrganizationPermission::OutboxRecover, $property)) {
                throw new LogicException('Outbox recovery is not authorized.');
            }
            $gate = DB::table('outbox_restore_gates')->where('property_id', $property->id)->lockForUpdate()->first();
            if ($gate === null || ! $gate->is_closed) {
                throw new LogicException('Close the restore gate before reconciliation.');
            }
            $messages = OutboxMessage::query()->where('property_id', $property->id)->whereNotIn('status', ['delivered', 'superseded'])->orderBy('created_at')->get();
            $groups = $messages->groupBy('integration');
            $reconciled = 0;

            foreach ($groups as $integration => $group) {
                $adapter = app(DeliveryAdapters::class)->for($integration);
                if (! $adapter instanceof RestoreReconciliationAdapter) {
                    if (! $adapter instanceof VerifiedSafeReplayAdapter || trim($adapter->safeReplayContract()) === '') {
                        throw new LogicException("Integration [{$integration}] has no verified restore recovery contract.");
                    }
                    $reports = [];
                    foreach ($group as $message) {
                        $reports[$message->id] = [
                            'outcome' => in_array($message->status, ['rejected', 'exhausted', 'blocked'], true) ? 'unresolved' : 'not_found',
                            'evidence' => 'verified-safe-replay:'.$adapter->safeReplayContract(),
                        ];
                    }
                } else {
                    $reports = $adapter->reconcile($group->all());
                }
                $expectedIds = $group->pluck('id')->all();
                if (array_diff($expectedIds, array_keys($reports)) !== [] || array_diff(array_keys($reports), $expectedIds) !== []) {
                    throw new LogicException("Integration [{$integration}] returned ambiguous reconciliation results.");
                }
                foreach ($group as $message) {
                    $report = $reports[$message->id];
                    if (! in_array($report['outcome'] ?? null, ['accepted', 'not_found', 'unresolved'], true) || trim((string) ($report['evidence'] ?? '')) === '') {
                        throw new LogicException("Integration [{$integration}] returned invalid reconciliation evidence.");
                    }
                    if ($report['outcome'] === 'accepted') {
                        if (! in_array($message->status, ['unknown', 'exhausted_unknown', 'sending', 'retryable', 'pending', 'blocked'], true)) {
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
                    } elseif ($report['outcome'] === 'not_found') {
                        if ($adapter instanceof VerifiedSafeReplayAdapter && in_array($message->status, ['sending', 'unknown', 'exhausted_unknown'], true)) {
                            $message->status = 'pending';
                            $message->setAttribute('next_attempt_at', null);
                            $message->setAttribute('claim_token', null);
                            $message->setAttribute('claim_expires_at', null);
                            $message->setAttribute('dispatch_claim_token', null);
                            $message->setAttribute('dispatch_claim_expires_at', null);
                            $message->save();
                        }
                    }
                    DB::table('outbox_restore_evidence')->insert([
                        'id' => (string) Str::ulid(),
                        'organization_id' => $message->organization_id,
                        'property_id' => $message->property_id,
                        'integration' => $integration,
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
                    $reconciled++;
                }
            }

            $unresolved = DB::table('outbox_messages as messages')
                ->where('messages.property_id', $property->id)
                ->whereNotIn('messages.status', ['delivered', 'superseded'])
                ->whereNotExists(fn ($query) => $query->selectRaw('1')
                    ->from('outbox_restore_evidence as evidence')
                    ->whereColumn('evidence.message_id', 'messages.id')
                    ->where('evidence.outcome', 'not_found'))
                ->count();
            if ($unresolved > 0) {
                return ['reconciled' => $reconciled, 'unresolved' => $unresolved];
            }
            app(RestoreGate::class)->recordDeliveryReconciled($property, $actor, $reason);

            return ['reconciled' => $reconciled, 'unresolved' => 0];
        }));
    }
}

// Never expose provider evidence values in serialized reports; evidence rows persist only hashes and references.
