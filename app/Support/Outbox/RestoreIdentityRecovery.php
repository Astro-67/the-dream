<?php

namespace App\Support\Outbox;

use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class RestoreIdentityRecovery
{
    public function recover(Property $property, User $actor, string $reason): int
    {
        $all = app(DeliveryAdapters::class)->all();
        if ($all === []) {
            throw new LogicException('No integration identity contracts are registered.');
        }
        $reports = [];
        foreach ($all as $integration => $adapter) {
            if (! $adapter instanceof RestoreIdentityBoundaryAdapter || ! $adapter->identityCoverageComplete()) {
                throw new LogicException("Integration [{$integration}] has no complete verified identity-boundary contract.");
            }
            $boundaries = $adapter->identityBoundaries($property);
            $reportedStreams = array_map(static fn (array $boundary): string => $boundary['subject_type'].'|'.$boundary['subject_id'], $boundaries);
            $restoredStreams = OutboxMessage::query()
                ->where('property_id', $property->id)
                ->where('integration', $integration)
                ->distinct()
                ->get(['subject_type', 'subject_id'])
                ->map(static fn (OutboxMessage $message): string => $message->subject_type.'|'.$message->subject_id)
                ->all();
            if (array_diff($restoredStreams, $reportedStreams) !== []) {
                throw new LogicException("Integration [{$integration}] omitted a restored stream identity boundary.");
            }
            foreach ($boundaries as $boundary) {
                $reports[] = [$integration, $boundary];
            }
            $hasMessages = OutboxMessage::query()->where('property_id', $property->id)->where('integration', $integration)->exists();
            if ($hasMessages && $boundaries === []) {
                throw new LogicException("Integration [{$integration}] omitted identity history for restored messages.");
            }
        }
        if ($reports === []) {
            throw new LogicException('No verified identity boundaries were returned.');
        }

        return DB::transaction(function () use ($property, $actor, $reason, $reports): int {
            Property::query()->whereKey($property->id)->lockForUpdate()->firstOrFail();
            $gate = DB::table('outbox_restore_gates')->where('property_id', $property->id)->lockForUpdate()->first();
            if ($gate === null || ! $gate->is_closed || $gate->delivery_reconciled_at === null) {
                throw new LogicException('Complete delivery reconciliation before identity recovery.');
            }
            foreach ($reports as [$integration, $boundary]) {
                if (! is_string($boundary['evidence']) || trim($boundary['evidence']) === ''
                    || ! is_int($boundary['highest_sequence']) || $boundary['highest_sequence'] < 0
                    || ! is_string($boundary['subject_type']) || trim($boundary['subject_type']) === ''
                    || ! is_string($boundary['subject_id']) || trim($boundary['subject_id']) === '') {
                    throw new LogicException('Provider returned an invalid identity boundary.');
                }
                $streamKey = hash('sha256', json_encode([
                    $property->organization_id,
                    $property->id,
                    $integration,
                    $boundary['subject_type'],
                    $boundary['subject_id'],
                ], JSON_THROW_ON_ERROR));
                $currentMaximum = (int) OutboxMessage::query()->where('stream_key', $streamKey)->max('sequence');
                $existingFloor = (int) DB::table('outbox_identity_floors')
                    ->where('organization_id', $property->organization_id)
                    ->where('property_id', $property->id)
                    ->where('integration', $integration)
                    ->where('subject_type', $boundary['subject_type'])
                    ->where('subject_id', $boundary['subject_id'])
                    ->value('highest_sequence');
                if ($boundary['highest_sequence'] < max($currentMaximum, $existingFloor)) {
                    throw new LogicException('Identity boundary cannot be lower than restored or previously verified sequence history.');
                }
                DB::table('outbox_identity_floors')->updateOrInsert(
                    [
                        'organization_id' => $property->organization_id,
                        'property_id' => $property->id,
                        'integration' => $integration,
                        'subject_type' => $boundary['subject_type'],
                        'subject_id' => $boundary['subject_id'],
                    ],
                    [
                        'id' => (string) Str::ulid(),
                        'highest_sequence' => $boundary['highest_sequence'],
                        'evidence_hash' => hash('sha256', $boundary['evidence']),
                        'actor_id' => $actor->id,
                        'reason' => $reason,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
            $evidence = array_map(static fn (array $report): string => $report[1]['evidence'], $reports);
            $hash = hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR));
            $integrations = array_values(array_unique(array_map(static fn (array $report): string => $report[0], $reports)));
            DB::table('outbox_restore_gates')->where('property_id', $property->id)->update([
                'identity_safe_at' => now(),
                'identity_evidence_hash' => $hash,
                'identity_reason' => $reason,
                'identity_actor_id' => $actor->id,
                'identity_method' => 'verified-boundary',
                'last_reason' => $reason,
                'updated_at' => now(),
            ]);
            Audit::record($actor, 'outbox_restore_identity_safe', $property, 'Recovered outbox identity boundaries', [
                'reason' => $reason,
                'integrations' => $integrations,
                'stream_count' => count($reports),
                'evidence_hash' => $hash,
            ]);

            return count($reports);
        });
    }
}
