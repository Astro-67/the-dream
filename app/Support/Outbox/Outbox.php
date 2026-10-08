<?php

namespace App\Support\Outbox;

use App\Domains\Tenancy\Models\Property;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use stdClass;

final class Outbox
{
    /**
     * Record an integration effect in the caller's business transaction.
     * The caller must hold the lock for the business subject.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(
        string $integration,
        Model|stdClass $subject,
        string $event,
        array $payload,
        string $idempotencyKey,
    ): OutboxMessage {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Outbox messages must be recorded inside the caller transaction.');
        }

        $context = Tenancy::current();
        if ($context === null || $context->isPlatform() || $context->propertyId === null) {
            throw new LogicException('Outbox messages require a property tenant context.');
        }

        $subjectType = $subject instanceof Model ? $subject->getMorphClass() : $subject->type;
        $subjectId = $subject instanceof Model ? (string) $subject->getKey() : $subject->id;
        if ($subject instanceof Model) {
            foreach (['organization_id' => $context->organizationId, 'property_id' => $context->propertyId] as $column => $expected) {
                if ($subject->getAttribute($column) !== $expected) {
                    throw new LogicException('Outbox subject must belong to the current property tenant.');
                }
            }
        } elseif ($subjectType === '' || $subjectId === '') {
            throw new LogicException('Outbox subject type and ID must not be empty.');
        }
        $property = Property::query()->whereKey($context->propertyId)->lockForUpdate()->first();
        if ($property === null || $property->archived_at !== null) {
            throw new LogicException('Outbox messages cannot be recorded for an archived property.');
        }

        $streamKey = hash('sha256', json_encode([$context->organizationId, $context->propertyId, $integration, $subjectType, $subjectId], JSON_THROW_ON_ERROR));
        $query = OutboxMessage::query()
            ->where('organization_id', $context->organizationId)
            ->where('property_id', $context->propertyId)
            ->where('integration', $integration)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId);

        $sequence = ((int) (clone $query)->max('sequence')) + 1;
        $message = new OutboxMessage([
            'integration' => $integration,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'sequence' => $sequence,
            'stream_key' => $streamKey,
            'idempotency_key' => $idempotencyKey.':'.$streamKey.':'.$sequence,
            'event' => $event,
            'payload' => $payload,
        ]);
        $message->save();

        $messageId = $message->id;
        $tenantContext = TenantContext::fromIds($context->organizationId, $context->propertyId);
        DB::afterCommit(function () use ($messageId, $tenantContext): void {
            Tenancy::within($tenantContext, function () use ($messageId): void {
                Queue::push(new DrainOutboxMessage($messageId));
            });
        });

        return $message;
    }
}
