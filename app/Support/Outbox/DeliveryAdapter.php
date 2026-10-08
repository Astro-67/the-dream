<?php

namespace App\Support\Outbox;

interface DeliveryAdapter
{
    /** @param list<OutboxMessage> $messages
     * @return list<DeliveryResult>
     */
    public function send(array $messages): array;
}
