<?php

namespace App\Support\Outbox;

interface RestoreReconciliationAdapter
{
    /** @param list<OutboxMessage> $messages
     * @return array<string, mixed> keyed by message ID; integration output is validated at runtime
     */
    public function reconcile(array $messages): array;
}
