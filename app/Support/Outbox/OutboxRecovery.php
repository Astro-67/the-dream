<?php

namespace App\Support\Outbox;

use App\Domains\Platform\Enums\PlatformRole;
use App\Domains\Platform\Policies\StaffPolicy;
use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

final class OutboxRecovery
{
    public function retry(User $actor, string $propertyId, string $messageId, string $reason): void
    {
        $this->authorized($actor, $propertyId, $messageId, 'outbox_recovery_refused', $reason, false, function (OutboxMessage $message) use ($actor, $reason): void {
            DB::transaction(function () use ($message, $actor, $reason): void {
                $locked = OutboxMessage::query()->whereKey($message->id)->lockForUpdate()->firstOrFail();
                if ($locked->claim_token !== null || ($locked->claim_expires_at !== null && CarbonImmutable::parse($locked->claim_expires_at)->isFuture()) || $locked->status === 'sending') {
                    throw new RuntimeException('An active delivery attempt prevents recovery.');
                }
                if (! in_array($locked->status, ['retryable', 'unknown', 'exhausted', 'exhausted_unknown'], true)) {
                    throw new RuntimeException('Only retryable or unknown delivery can be restarted.');
                }
                if (in_array($locked->status, ['unknown', 'exhausted_unknown'], true)
                    && ! app(DeliveryAdapters::class)->for($locked->integration)->policy()->unknownRetrySafe) {
                    throw new RuntimeException('Unknown delivery requires reconciliation before retry.');
                }

                $locked->status = 'retryable';
                $locked->setAttribute('next_attempt_at', now());
                $locked->setAttribute('dispatch_claim_token', null);
                $locked->setAttribute('dispatch_claim_expires_at', null);
                $locked->save();
                Audit::record($actor, 'outbox_recovery', $locked, 'Restarted outbox delivery', ['reason' => $reason, 'outcome' => 'retryable']);
                $id = $locked->id;
                $organizationId = $locked->organization_id;
                $propertyId = $locked->property_id;
                DB::afterCommit(fn () => $this->dispatch($id, $organizationId, $propertyId));
            });
        });
    }

    /** @param array<string, mixed> $correction */
    public function correct(User $actor, string $propertyId, string $messageId, string $reason, array $correction): void
    {
        $this->authorized($actor, $propertyId, $messageId, 'outbox_recovery_refused', $reason, false, function (OutboxMessage $message) use ($actor, $reason, $correction): void {
            $adapter = app(DeliveryAdapters::class)->for($message->integration);
            if (! $adapter instanceof OutboxCorrectionAdapter || ! $adapter->changedPayloadKeyReuseSafe()) {
                throw new RuntimeException('The adapter has not verified changed-payload idempotency-key reuse.');
            }
            DB::transaction(function () use ($message, $actor, $reason, $correction, $adapter): void {
                $property = Property::query()->whereKey($message->property_id)->lockForUpdate()->firstOrFail();
                if ($property->archived_at !== null) {
                    throw new RuntimeException('Archived property outbox operations cannot be corrected.');
                }
                $locked = OutboxMessage::query()->whereKey($message->id)->lockForUpdate()->firstOrFail();
                if ($locked->claim_token !== null || ($locked->claim_expires_at !== null && CarbonImmutable::parse($locked->claim_expires_at)->isFuture()) || $locked->status === 'sending' || $locked->attempts()->where('outcome', 'sending')->exists()) {
                    throw new RuntimeException('An active delivery attempt prevents recovery.');
                }
                if ($locked->status !== 'rejected') {
                    throw new RuntimeException('Only confirmed rejection can be corrected.');
                }
                $successors = OutboxMessage::query()->where('stream_key', $locked->stream_key)->where('sequence', '>', $locked->sequence)->where('status', 'blocked')->orderBy('sequence')->lockForUpdate()->get();
                if ($successors->contains(fn (OutboxMessage $item): bool => $item->attempt_count > 0 || $item->claim_token !== null)) {
                    throw new RuntimeException('Attempted successor snapshots cannot be corrected.');
                }
                $original = $locked->payload;
                $payload = $adapter->correct($locked, $correction);
                if ($payload === $original) {
                    throw new RuntimeException('Correction must change the rejected payload.');
                }
                $locked->revisions()->create([
                    'organization_id' => $locked->organization_id,
                    'property_id' => $locked->property_id,
                    'revision' => (int) $locked->revisions()->max('revision') + 1,
                    'payload' => $original,
                    'reason' => $reason,
                ]);
                $rebuilt = [];
                $previous = $payload;
                foreach ($successors as $successor) {
                    $old = $successor->payload;
                    $next = $adapter->rebuildSuccessor($successor, $previous);
                    if ($next === $old && $next === $previous) {
                        throw new RuntimeException('Successor snapshot did not preserve corrected stream state.');
                    }
                    $rebuilt[] = [$successor, $old, $next];
                    $previous = $next;
                }
                app(OutboxCorrectionContext::class)->run(function () use ($locked, $payload): void {
                    $locked->payload = $payload;
                    $locked->status = 'pending';
                    $locked->setAttribute('next_attempt_at', null);
                    $locked->setAttribute('dispatch_claim_token', null);
                    $locked->setAttribute('dispatch_claim_expires_at', null);
                    $locked->save();
                });
                foreach ($rebuilt as [$successor, $old, $next]) {
                    $successor->revisions()->create([
                        'organization_id' => $successor->organization_id,
                        'property_id' => $successor->property_id,
                        'revision' => (int) $successor->revisions()->max('revision') + 1,
                        'payload' => $old,
                        'reason' => $reason,
                    ]);
                    app(OutboxCorrectionContext::class)->run(function () use ($successor, $next): void {
                        $successor->payload = $next;
                        $successor->save();
                    });
                }
                Audit::record($actor, 'outbox_recovery', $locked, 'Corrected rejected outbox operation', [
                    'reason' => $reason,
                    'outcome' => 'corrected',
                    'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                ]);
                $id = $locked->id;
                $organizationId = $locked->organization_id;
                $propertyId = $locked->property_id;
                DB::afterCommit(fn () => $this->dispatch($id, $organizationId, $propertyId));
            });
        });
    }

    public function confirmAccepted(User $actor, string $propertyId, string $messageId, string $reason, string $evidence): void
    {
        if (trim($evidence) === '') {
            throw new RuntimeException('Acceptance evidence is required.');
        }
        $this->authorized($actor, $propertyId, $messageId, 'outbox_recovery_refused', $reason, false, function (OutboxMessage $message) use ($actor, $reason, $evidence): void {
            DB::transaction(function () use ($message, $actor, $reason, $evidence): void {
                $locked = OutboxMessage::query()->whereKey($message->id)->lockForUpdate()->firstOrFail();
                if ($locked->claim_token !== null || ($locked->claim_expires_at !== null && CarbonImmutable::parse($locked->claim_expires_at)->isFuture()) || $locked->status === 'sending') {
                    throw new RuntimeException('An active delivery attempt prevents recovery.');
                }
                if (! in_array($locked->status, ['unknown', 'exhausted_unknown'], true)) {
                    throw new RuntimeException('Only uncertain delivery can be confirmed accepted.');
                }
                $locked->status = 'delivered';
                $locked->delivered_at = now();
                $locked->setAttribute('next_attempt_at', null);
                $locked->save();
                Audit::record($actor, 'outbox_recovery', $locked, 'Confirmed outbox acceptance', ['reason' => $reason, 'outcome' => 'delivered', 'evidence_hash' => hash('sha256', $evidence)]);
                $stream = $locked->stream_key;
                $sequence = $locked->sequence;
                DB::afterCommit(fn () => app(DeliveryEngine::class)->releaseRecoveredSuccessor($stream, $sequence));
            });
        });
    }

    /** @return array<string, mixed> */
    public function showBody(User $actor, string $propertyId, string $messageId, string $reason): array
    {
        return $this->authorized($actor, $propertyId, $messageId, 'outbox_body_read_refused', $reason, true, function (OutboxMessage $message) use ($actor, $reason): array {
            $payload = json_decode(Crypt::decryptString($message->getRawOriginal('payload')), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                throw new RuntimeException('The outbox payload is not a JSON object.');
            }

            Audit::record($actor, 'outbox_body_read', $message, 'Read outbox payload', ['reason' => $reason, 'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))]);

            return $payload;
        });
    }

    private function authorized(User $actor, string $propertyId, string $messageId, string $refusedEvent, string $reason, bool $raw, callable $operation): mixed
    {
        if (trim($reason) === '') {
            throw new RuntimeException('A reason is required.');
        }
        $property = app(OutboxPropertyLookup::class)->find($propertyId);
        if ($property === null) {
            throw new RuntimeException('The property does not exist.');
        }
        $context = TenantContext::forProperty($property);
        $message = Tenancy::within($context, fn () => OutboxMessage::query()->whereKey($messageId)->first());
        try {
            if ($message === null) {
                throw new RuntimeException('Outbox message not found in the requested property.');
            }

            return Tenancy::within($context, function () use ($actor, $property, $message, $operation, $raw): mixed {
                if ($raw) {
                    if (! Access::allows($actor, OrganizationPermission::OutboxRawRead, $property)) {
                        throw new RuntimeException('Raw outbox access is not authorized.');
                    }
                } elseif (! $this->canRecover($actor, $property, $message)) {
                    throw new RuntimeException('Outbox recovery is not authorized.');
                }

                return $operation($message);
            });
        } catch (\Throwable $failure) {
            if ($message !== null) {
                try {
                    Tenancy::within($context, function () use ($actor, $message, $refusedEvent, $reason): void {
                        Audit::record($actor, $refusedEvent, $message, 'Refused outbox recovery action', ['reason' => $reason, 'outcome' => 'refused']);
                    });
                } catch (\Throwable) {
                    // Preserve the original refusal if the audit store is unavailable.
                }
            }
            throw $failure;
        }
    }

    private function canRecover(User $actor, Property $property, OutboxMessage $message): bool
    {
        if ($message->property_id !== $property->id) {
            return false;
        }
        if ($actor->hasPlatformRole(PlatformRole::ComplianceOperator)) {
            return app(StaffPolicy::class)->recoverComplianceOutbox($actor, $message);
        }

        return Access::allows($actor, OrganizationPermission::OutboxRecover, $property);
    }

    private function dispatch(string $messageId, string $organizationId, string $propertyId): void
    {
        Tenancy::within(TenantContext::fromIds($organizationId, $propertyId), function () use ($messageId): void {
            Queue::push(new DrainOutboxMessage($messageId));
        });
    }
}
