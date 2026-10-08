<?php

namespace Tests\Fakes;

use App\Domains\Tenancy\Models\Property;
use App\Support\Outbox\DeliveryAdapter;
use App\Support\Outbox\DeliveryPolicy;
use App\Support\Outbox\DeliveryResult;
use App\Support\Outbox\OutboxMessage;
use App\Support\Outbox\RestoreIdentityBoundaryAdapter;
use App\Support\Outbox\RestoreReconciliationAdapter;

final class ReconcilingOutboxAdapter implements DeliveryAdapter, RestoreIdentityBoundaryAdapter, RestoreReconciliationAdapter
{
    /** @var array<string, array{outcome: 'accepted'|'not_found'|'unresolved', evidence: string, references?: array<string, string>}> */
    public array $reports = [];

    /** @var list<array{subject_type: string, subject_id: string, highest_sequence: int, evidence: string}> */
    public array $identityFloors = [];

    public bool $fail = false;

    public bool $completeIdentityCoverage = true;

    public function policy(): DeliveryPolicy
    {
        return new DeliveryPolicy;
    }

    public function send(array $messages): array
    {
        return array_map(static fn (OutboxMessage $message): DeliveryResult => new DeliveryResult('accepted'), $messages);
    }

    public function reconcile(array $messages): array
    {
        if ($this->fail) {
            throw new \RuntimeException('Fake provider reconciliation interrupted.');
        }

        $reports = [];
        foreach ($messages as $message) {
            $reports[$message->id] = $this->reports[$message->id] ?? ['outcome' => 'unresolved', 'evidence' => 'No fake provider record found'];
        }

        return $reports;
    }

    public function identityCoverageComplete(): bool
    {
        return $this->completeIdentityCoverage;
    }

    public function identityBoundaries(Property $property): array
    {
        return $this->identityFloors;
    }
}
