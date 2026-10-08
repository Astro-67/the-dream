<?php

namespace App\Support\Outbox;

use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

final class DeliveryEngine
{
    public function __construct(private DeliveryAdapters $adapters) {}

    /** @param list<string> $messageIds */
    public function deliverBatch(array $messageIds): void
    {
        $messageIds = array_values(array_unique($messageIds));
        while ($messageIds !== []) {
            $first = OutboxMessage::query()->whereKey($messageIds[0])->first();
            if ($first === null) {
                array_shift($messageIds);

                continue;
            }
            $policy = $this->adapters->for($first->integration)->policy();
            $group = [$messageIds[0]];
            $remainder = [];
            foreach (array_slice($messageIds, 1) as $id) {
                $message = OutboxMessage::query()->whereKey($id)->first();
                if ($message !== null && $message->integration === $first->integration && $this->matchesGrouping($first, $message, $policy->batchGrouping) && count($group) < $policy->maxBatchSize) {
                    $group[] = $id;
                } else {
                    $remainder[] = $id;
                }
            }
            $messageIds = $remainder;
            $this->deliverGroup($group, $policy);
        }
    }

    private function matchesGrouping(OutboxMessage $first, OutboxMessage $message, array $fields): bool
    {
        foreach ($fields as $field) {
            if (! in_array($field, ['organization_id', 'property_id', 'integration'], true)
                || $first->getAttribute($field) !== $message->getAttribute($field)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $messageIds */
    private function deliverGroup(array $messageIds, DeliveryPolicy $policy): void
    {
        if ($policy->quotaIdentity !== null && ! app(QuotaLimiter::class)->acquire($policy, count($messageIds))) {
            return;
        }
        if ($policy->maxBatchSize === 1) {
            foreach ($messageIds as $messageId) {
                $this->deliverOne($messageId, null, false);
            }

            return;
        }
        $claims = [];
        $reservedStreams = [];
        foreach ($messageIds as $messageId) {
            $candidate = OutboxMessage::query()->whereKey($messageId)->first();
            if ($candidate === null || isset($reservedStreams[$candidate->stream_key])) {
                continue;
            }
            $claim = $this->claim($messageId);
            if ($claim !== null) {
                $reservedStreams[$claim->stream_key] = true;
                $claims[] = $claim;
            }
        }
        if ($claims === []) {
            return;
        }

        $results = [];
        try {
            $snapshots = array_map(static function (OutboxMessage $claim): OutboxMessage {
                $snapshot = $claim->replicate();
                $snapshot->id = $claim->id;
                $snapshot->organization_id = $claim->organization_id;
                $snapshot->property_id = $claim->property_id;

                return $snapshot;
            }, $claims);
            $results = $this->adapters->for($claims[0]->integration)->send($snapshots);
        } catch (Throwable) {
            // A transport exception does not establish provider acceptance.
        }
        $mapped = $this->mapBatchResults($results, $claims);
        foreach ($claims as $index => $claim) {
            $this->finish($claim, $mapped[$index], false);
        }
    }

    public function deliver(string $messageId, ?string $dispatchClaimToken = null): void
    {
        $this->deliverOne($messageId, $dispatchClaimToken, true);
    }

    private function deliverOne(string $messageId, ?string $dispatchClaimToken, bool $releaseSuccessor): void
    {
        $message = OutboxMessage::query()->whereKey($messageId)->first();
        if ($message === null) {
            return;
        }
        $policy = $this->adapters->for($message->integration)->policy();
        if ($policy->quotaIdentity !== null && ! app(QuotaLimiter::class)->acquire($policy)) {
            return;
        }
        $claim = $this->claim($messageId, $dispatchClaimToken);
        if ($claim === null) {
            return;
        }
        $result = null;
        try {
            $snapshot = $claim->replicate();
            $snapshot->id = $claim->id;
            $snapshot->organization_id = $claim->organization_id;
            $snapshot->property_id = $claim->property_id;
            $result = $this->mappedResult($this->adapters->for($claim->integration)->send([$snapshot]));
        } catch (Throwable) {
            // A transport exception does not prove whether the provider accepted the request.
        }
        $this->finish($claim, $result ?? new DeliveryResult('unknown'));
    }

    private function claim(string $messageId, ?string $dispatchClaimToken = null): ?OutboxMessage
    {
        $claimToken = bin2hex(random_bytes(32));

        return DB::transaction(function () use ($messageId, $claimToken, $dispatchClaimToken): ?OutboxMessage {
            $message = OutboxMessage::query()->whereKey($messageId)->lockForUpdate()->first();
            if ($message === null) {
                return null;
            }
            if ($dispatchClaimToken !== null) {
                if ($message->dispatch_claim_token !== $dispatchClaimToken) {
                    return null;
                }
                $message->dispatch_claim_token = null;
                $message->dispatch_claim_expires_at = null;
                $message->save();
            }

            $adapter = $this->adapters->for($message->integration);
            $policy = $adapter->policy();
            $dueRetry = $message->status === 'retryable'
                && ($message->next_attempt_at === null || CarbonImmutable::parse($message->next_attempt_at)->isPast());
            $safeUnknownRetry = $message->status === 'unknown'
                && $policy->unknownRetrySafe
                && ($message->next_attempt_at === null || CarbonImmutable::parse($message->next_attempt_at)->isPast());
            if ($message->status !== 'pending' && ! $dueRetry && ! $safeUnknownRetry) {
                return null;
            }
            if ($message->attempt_count >= $policy->maxAttempts) {
                $message->status = $message->status === 'unknown' ? 'exhausted_unknown' : 'exhausted';
                $message->save();

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
            OutboxAttempt::query()->create([
                'organization_id' => $message->organization_id,
                'property_id' => $message->property_id,
                'outbox_message_id' => $message->id,
                'attempt_number' => $message->attempt_count,
                'outcome' => 'sending',
                'request_body' => json_encode($message->payload, JSON_THROW_ON_ERROR),
            ]);

            return $message;
        });
    }

    private function finish(OutboxMessage $claim, DeliveryResult $result, bool $releaseSuccessor = true): void
    {
        $claimToken = $claim->claim_token;
        $accepted = DB::transaction(function () use ($claim, $claimToken, $result): bool {
            $current = OutboxMessage::query()->whereKey($claim->id)->lockForUpdate()->first();
            $attempt = OutboxAttempt::query()
                ->where('outbox_message_id', $claim->id)
                ->where('attempt_number', $claim->attempt_count)
                ->lockForUpdate()
                ->first();
            if ($current === null || $current->claim_token !== $claimToken) {
                return false;
            }
            if ($attempt !== null) {
                $attempt->outcome = $result->outcome;
                $attempt->response_body = $result->responseBody === null ? null : json_encode($result->responseBody, JSON_THROW_ON_ERROR);
                $attempt->setAttribute('provider_references', $result->references ?: null);
                $attempt->setAttribute('retry_at', $result->retryAt);
                $attempt->save();
            }
            $policy = $this->adapters->for($current->integration)->policy();
            $retryable = $result->outcome === 'retryable' && $current->attempt_count < $policy->maxAttempts;
            $outcome = $result->outcome;
            if ($result->outcome === 'unknown' && $current->attempt_count >= $policy->maxAttempts) {
                $outcome = 'exhausted_unknown';
            } elseif ($result->outcome === 'retryable' && ! $retryable) {
                $outcome = 'exhausted';
            }
            $current->status = $outcome === 'accepted' ? 'delivered' : $outcome;
            $current->setAttribute('provider_references', $result->references ?: null);
            $retryAt = $result->retryAt ?? now()->addSeconds($policy->retryDelaySeconds);
            $current->setAttribute('next_attempt_at', in_array($outcome, ['retryable', 'unknown'], true) ? $retryAt : null);
            $current->delivered_at = $result->outcome === 'accepted' ? now() : null;
            $current->claim_token = null;
            $current->claim_expires_at = null;
            $current->dispatch_claim_token = null;
            $current->dispatch_claim_expires_at = null;
            $current->save();

            return $result->outcome === 'accepted';
        });
        if ($accepted && $releaseSuccessor) {
            $this->releaseNext($claim->stream_key, $claim->sequence);
        }
    }

    /** @param list<mixed> $results */
    private function mapBatchResults(array $results, array $claims): array
    {
        $unknown = array_fill(0, count($claims), new DeliveryResult('unknown'));
        if (count($results) !== count($claims)) {
            return $unknown;
        }
        $byId = [];
        foreach ($results as $result) {
            if (! $result instanceof DeliveryResult || $result->messageId === null || isset($byId[$result->messageId])) {
                return $unknown;
            }
            $byId[$result->messageId] = $result;
        }
        foreach ($claims as $claim) {
            if (! isset($byId[$claim->id])) {
                return $unknown;
            }
        }

        return array_map(static fn (OutboxMessage $claim) => $byId[$claim->id], $claims);
    }

    /** @param list<mixed> $results */
    private function mappedResult(array $results): ?DeliveryResult
    {
        if (count($results) !== 1) {
            return null;
        }
        $candidate = $results[0];

        return is_object($candidate) && get_class($candidate) === DeliveryResult::class ? $candidate : null;
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
            $organizationId = $next->organization_id;
            $propertyId = $next->property_id;
            DB::afterCommit(fn () => $this->dispatch($messageId, $organizationId, $propertyId));
        });
    }

    private function dispatch(string $messageId, string $organizationId, string $propertyId): void
    {
        Tenancy::within(TenantContext::fromIds($organizationId, $propertyId), function () use ($messageId): void {
            Queue::push(new DrainOutboxMessage($messageId));
        });
    }
}
