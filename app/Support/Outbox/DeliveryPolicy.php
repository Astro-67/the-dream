<?php

namespace App\Support\Outbox;

use InvalidArgumentException;

final readonly class DeliveryPolicy
{
    public function __construct(
        public bool $unknownRetrySafe = false,
        public int $maxAttempts = 5,
        public int $retryDelaySeconds = 60,
        public string $ordering = 'ordered',
        public mixed $coalesceKey = null,
    ) {
        if ($maxAttempts < 1 || $retryDelaySeconds < 1) {
            throw new InvalidArgumentException('Outbox retry limits must be positive.');
        }
        if (! in_array($ordering, ['ordered', 'latest-only'], true)) {
            throw new InvalidArgumentException('Outbox ordering must be ordered or latest-only.');
        }
    }

    public function latestOnly(): bool
    {
        return $this->ordering === 'latest-only';
    }

    public function key(string $coalesceKey): string
    {
        if ($coalesceKey === '') {
            throw new InvalidArgumentException('Latest-only outbox messages require a coalesce key.');
        }

        return hash('sha256', $coalesceKey);
    }
}
