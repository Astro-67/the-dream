<?php

namespace App\Support\Outbox;

use InvalidArgumentException;

final readonly class DeliveryPolicy
{
    /**
     * @param  list<string>  $batchGrouping
     */
    public function __construct(
        public bool $unknownRetrySafe = false,
        public int $maxAttempts = 5,
        public int $retryDelaySeconds = 60,
        public array $batchGrouping = [],
        public int $maxBatchSize = 1,
        public ?string $quotaIdentity = null,
        public ?int $quotaLimit = null,
        public int $quotaWindowSeconds = 60,
        public string $ordering = 'ordered',
        public mixed $coalesceKey = null,
    ) {
        if ($maxAttempts < 1 || $retryDelaySeconds < 1 || $maxBatchSize < 1 || $quotaWindowSeconds < 1
            || ($quotaIdentity === null) !== ($quotaLimit === null) || ($quotaLimit !== null && $quotaLimit < 1)
            || ($quotaIdentity !== null && trim($quotaIdentity) === '')
            || array_diff($batchGrouping, ['organization_id', 'property_id', 'integration']) !== []) {
            throw new InvalidArgumentException('Outbox policy values must be valid and positive.');
        }
        if (! in_array($ordering, ['ordered', 'latest-only'], true)) {
            throw new InvalidArgumentException('Outbox ordering must be ordered or latest-only.');
        }
        if ($ordering === 'latest-only' && ! is_callable($coalesceKey)) {
            throw new InvalidArgumentException('Latest-only outbox policy must define its coalesce key.');
        }
        if ($ordering === 'ordered' && $coalesceKey !== null) {
            throw new InvalidArgumentException('Ordered outbox policy cannot define a coalesce key.');
        }
    }

    public function latestOnly(): bool
    {
        return $this->ordering === 'latest-only';
    }

    /** @param array<string, mixed> $payload */
    public function coalesceKeyFor(array $payload): ?string
    {
        if (! $this->latestOnly()) {
            return null;
        }

        $key = ($this->coalesceKey)($payload);
        if (! is_string($key) || $key === '') {
            throw new InvalidArgumentException('Latest-only outbox messages require a non-empty coalesce key.');
        }

        return $key;
    }

    public function keyHash(string $organizationId, string $propertyId, string $integration, string $key): string
    {
        return hash('sha256', json_encode([$organizationId, $propertyId, $integration, $key], JSON_THROW_ON_ERROR));
    }

}
