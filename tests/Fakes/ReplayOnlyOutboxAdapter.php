<?php

namespace Tests\Fakes;

use App\Support\Outbox\DeliveryPolicy;
use App\Support\Outbox\DeliveryResult;
use App\Support\Outbox\OutboxMessage;
use App\Support\Outbox\VerifiedSafeReplayAdapter;

final class ReplayOnlyOutboxAdapter implements VerifiedSafeReplayAdapter
{
    public function policy(): DeliveryPolicy
    {
        return new DeliveryPolicy(unknownRetrySafe: false);
    }

    public function send(array $messages): array
    {
        return array_map(static fn (OutboxMessage $message): DeliveryResult => new DeliveryResult('accepted'), $messages);
    }

    public function safeReplayContract(): string
    {
        return 'fake-contract-v1';
    }
}
