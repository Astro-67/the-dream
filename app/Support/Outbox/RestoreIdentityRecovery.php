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
    public function recover(Property $property, User $actor, string $integration, string $reason): int
    {
        $adapter = app(DeliveryAdapters::class)->for($integration);
        if (! $adapter instanceof RestoreIdentityBoundaryAdapter) {
            throw new LogicException("Integration [{$integration}] has no verified identity-boundary contract.");
        }
        $boundaries = $adapter->identityBoundaries($property);
        if ($boundaries === []) {
            throw new LogicException('The provider returned no verified identity boundaries.');
        }

        return DB::transaction(function () use ($property, $actor, $integration, $reason, $boundaries): int {
            $gate = DB::table('outbox_restore_gates')->where('property_id', $property->id)->lockForUpdate()->first();
            if ($gate === null || ! $gate->is_closed || $gate->delivery_reconciled_at === null) {
                throw new LogicException('Complete delivery reconciliation before identity recovery.');
            }
            foreach ($boundaries as $boundary) {
                if (trim($boundary['evidence']) === '' || ! is_int($boundary['highest_sequence']) || $boundary['highest_sequence'] < 0
                    || trim($boundary['subject_type']) === '' || trim($boundary['subject_id']) === '') {
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
            $hash = hash('sha256', json_encode(array_column($boundaries, 'evidence'), JSON_THROW_ON_ERROR));
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
                'integration' => $integration,
                'stream_count' => count($boundaries),
                'evidence_hash' => $hash,
            ]);

            return count($boundaries);
        });
    }
}
