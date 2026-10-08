<?php

namespace App\Support\Outbox;

interface RestoreReconciliationAdapter
{
    /** @param list<OutboxMessage> $messages
     * @return array<string, array{outcome: 'accepted'|'not_found'|'unresolved', evidence: string, references?: array<string, string>}> keyed by message ID
     */
    public function reconcile(array $messages): array;
}
