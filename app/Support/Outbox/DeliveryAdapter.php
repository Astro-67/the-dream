<?php

namespace App\Support\Outbox;

interface DeliveryAdapter
{
    public function policy(): DeliveryPolicy;

    /** @param list<OutboxMessage> $messages
     * @return list<DeliveryResult>
     */
    public function send(array $messages): array;
}
