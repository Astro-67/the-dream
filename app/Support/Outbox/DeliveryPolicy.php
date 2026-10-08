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
        public bool $changedPayloadKeyReuseSafe = false,
    ) {
        if ($maxAttempts < 1 || $retryDelaySeconds < 1 || $maxBatchSize < 1 || $quotaWindowSeconds < 1
            || ($quotaIdentity === null) !== ($quotaLimit === null) || ($quotaLimit !== null && $quotaLimit < 1)
            || ($quotaIdentity !== null && trim($quotaIdentity) === '')
            || array_diff($batchGrouping, ['organization_id', 'property_id', 'integration']) !== []) {
            throw new InvalidArgumentException('Outbox policy values must be valid and positive.');
        }
    }
}
