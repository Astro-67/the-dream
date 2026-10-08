<?php

namespace App\Support\Outbox;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DeliveryResult
{
    /**
     * @param  array<string, scalar|null>  $references
     * @param  array<string, mixed>|null  $responseBody
     */
    public function __construct(
        public string $outcome,
        public array $references = [],
        public ?array $responseBody = null,
        public ?DateTimeImmutable $retryAt = null,
        public ?string $messageId = null,
    ) {
        if (! in_array($outcome, ['accepted', 'rejected', 'retryable', 'unknown'], true)) {
            throw new InvalidArgumentException('Invalid outbox delivery outcome.');
        }
    }
}
