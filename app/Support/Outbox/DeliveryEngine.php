<?php

namespace App\Support\Outbox;

use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

final class DeliveryEngine
{
    public function __construct(private DeliveryAdapters $adapters) {}

    public function deliver(string $messageId): void
    {
        $claimToken = bin2hex(random_bytes(32));
        $claim = DB::transaction(function () use ($messageId, $claimToken): ?OutboxMessage {
            $message = OutboxMessage::query()->whereKey($messageId)->lockForUpdate()->first();
            if ($message === null || $message->status !== 'pending') {
                return null;
            }

            $predecessor = OutboxMessage::query()
                ->where('stream_key', $message->stream_key)
                ->where('sequence', '<', $message->sequence)
                ->where('status', '!=', 'delivered')
                ->exists();
            if ($predecessor) {
                $message->status = 'blocked';
                $message->save();

                return null;
            }

            $message->status = 'sending';
            $message->claim_token = $claimToken;
            $message->claim_expires_at = now()->addMinutes(2);
            $message->attempt_count++;
            $message->save();

            return $message;
        });

        if ($claim === null) {
            return;
        }

        $snapshot = $claim->replicate();
        $snapshot->id = $claim->id;
        $snapshot->organization_id = $claim->organization_id;
        $snapshot->property_id = $claim->property_id;
        $requestBody = $snapshot->payload;
        $result = null;
        try {
            $results = $this->adapters->for($snapshot->integration)->send([$snapshot]);
            if (count($results) === 1) {
                $result = $results[0];
            }
        } catch (Throwable) {
            // A transport exception does not prove whether the provider accepted the request.
        }
        $result ??= new DeliveryResult('unknown');

        $released = DB::transaction(function () use ($claim, $claimToken, $result, $requestBody): bool {
            $current = OutboxMessage::query()->whereKey($claim->id)->lockForUpdate()->first();
            if ($current === null || $current->claim_token !== $claimToken) {
                return false;
            }

            OutboxAttempt::query()->create([
                'outbox_message_id' => $current->id,
                'attempt_number' => $current->attempt_count,
                'outcome' => $result->outcome,
                'request_body' => json_encode($requestBody, JSON_THROW_ON_ERROR),
                'response_body' => $result->responseBody === null ? null : json_encode($result->responseBody, JSON_THROW_ON_ERROR),
                'provider_references' => $result->references ?: null,
                'retry_at' => $result->retryAt,
            ]);

            $current->status = $result->outcome === 'accepted' ? 'delivered' : $result->outcome;
            $current->setAttribute('provider_references', $result->references ?: null);
            $current->setAttribute('next_attempt_at', $result->outcome === 'retryable' ? ($result->retryAt ?? now()->addMinute()) : null);
            $current->delivered_at = $result->outcome === 'accepted' ? now() : null;
            $current->claim_token = null;
            $current->claim_expires_at = null;
            $current->save();

            return $result->outcome === 'accepted';
        });

        if ($released) {
            $this->releaseNext($claim->stream_key, $claim->sequence);
        }
    }

    private function releaseNext(string $streamKey, int $sequence): void
    {
        DB::transaction(function () use ($streamKey, $sequence): void {
            $next = OutboxMessage::query()
                ->where('stream_key', $streamKey)
                ->where('sequence', $sequence + 1)
                ->where('status', 'blocked')
                ->lockForUpdate()
                ->first();

            if ($next === null) {
                return;
            }

            $hasUndeliveredPredecessor = OutboxMessage::query()
                ->where('stream_key', $streamKey)
                ->where('sequence', '<', $next->sequence)
                ->where('status', '!=', 'delivered')
                ->exists();
            if ($hasUndeliveredPredecessor) {
                return;
            }

            $next->status = 'pending';
            $next->save();
            $messageId = $next->id;
            DB::afterCommit(fn () => $this->dispatch($messageId, $next->organization_id, $next->property_id));
        });
    }

    private function dispatch(string $messageId, string $organizationId, string $propertyId): void
    {
        $context = TenantContext::fromIds($organizationId, $propertyId);
        Tenancy::within($context, function () use ($messageId): void {
            Queue::push(new DrainOutboxMessage($messageId));
        });
    }
}
