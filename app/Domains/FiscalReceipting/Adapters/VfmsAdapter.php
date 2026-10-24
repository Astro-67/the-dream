<?php

namespace App\Domains\FiscalReceipting\Adapters;

use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\Tenancy\Models\Property;
use App\Support\Outbox\DeliveryAdapter;
use App\Support\Outbox\DeliveryPolicy;
use App\Support\Outbox\DeliveryResult;

/**
 * The outbox adapter for VFMS: one message per stay at a time, in order. A message
 * goes live only when it was recorded live and the property is still live.
 */
final class VfmsAdapter implements DeliveryAdapter
{
    public const INTEGRATION = 'vfms';

    public function __construct(private readonly SandboxVfms $sandbox, private readonly HttpVfms $http) {}

    public function policy(): DeliveryPolicy
    {
        // shortcut: unknown outcomes stay unknown for the compliance operator; #165 adds accommodation-report reconciliation.
        return new DeliveryPolicy(unknownRetrySafe: false, maxBatchSize: 1, ordering: 'ordered');
    }

    public function send(array $messages): array
    {
        return array_map(function ($message) {
            $credential = VfmsCredential::for(Property::query()->findOrFail($message->property_id));
            $live = $message->payload['mode'] === VfmsMode::Live->value && $credential->mode === VfmsMode::Live;
            if ($live && ! $credential->isComplete()) {
                return new DeliveryResult('rejected', ['mode' => 'live'], ['error' => 'VFMS credentials are incomplete.'], messageId: $message->id);
            }
            $result = ($live ? $this->http : $this->sandbox)->send($message->payload, $credential);

            return new DeliveryResult($result->outcome, ['mode' => $live ? 'live' : 'sandbox', ...$result->references], $result->responseBody, $result->retryAt, $message->id);
        }, $messages);
    }
}
