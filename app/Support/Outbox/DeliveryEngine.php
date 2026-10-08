<?php

namespace App\Support\Outbox;

use Illuminate\Support\Facades\DB;
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

        // Use a detached immutable snapshot so the transport sees no claim ORM instance.
        $message = $claim->replicate();
        $message->id = $claim->id;
        $message->organization_id = $claim->organization_id;
        $message->property_id = $claim->property_id;
        $requestBody = $message->payload;
        $result = null;
        try {
            $results = $this->adapters->for($message->integration)->send([$message]);
            if (count($results) === 1 && $results[0] instanceof DeliveryResult) {
                $result = $results[0];
            }
        } catch (Throwable) {
            // A transport exception does not prove whether the provider accepted the request.
        }
        $result ??= new DeliveryResult('unknown');

        DB::transaction(function () use ($claim, $claimToken, $result, $requestBody): void {
            $current = OutboxMessage::query()->whereKey($claim->id)->lockForUpdate()->first();
            if ($current === null || $current->claim_token !== $claimToken) {
                return;
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
            $current->provider_references = $result->references ?: null;
            $current->next_attempt_at = $result->outcome === 'retryable' ? ($result->retryAt ?? now()->addMinute()) : null;
            $current->delivered_at = $result->outcome === 'accepted' ? now() : null;
            $current->claim_token = null;
            $current->claim_expires_at = null;
            $current->save();
        });
    }
}
