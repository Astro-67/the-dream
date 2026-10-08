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
                    if (! $actor->can('outbox.raw.read')) {
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
