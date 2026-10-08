<?php

namespace App\Support\Outbox;

use InvalidArgumentException;

final readonly class DeliveryPolicy
{
    public function __construct(
        public bool $unknownRetrySafe = false,
        public int $maxAttempts = 5,
        public int $retryDelaySeconds = 60,
    ) {
        if ($maxAttempts < 1 || $retryDelaySeconds < 1) {
            throw new InvalidArgumentException('Outbox retry limits must be positive.');
        }
    }
}
