<?php

namespace App\Support\Outbox;

use LogicException;

final class DeliveryAdapters
{
    /** @var array<string, DeliveryAdapter> */
    private array $adapters = [];

    public function register(string $integration, DeliveryAdapter $adapter): void
    {
        if (isset($this->adapters[$integration])) {
            throw new LogicException("An outbox adapter is already registered for [{$integration}].");
        }

        $this->adapters[$integration] = $adapter;
    }

    public function for(string $integration): DeliveryAdapter
    {
        return $this->adapters[$integration] ?? throw new LogicException("No outbox adapter is registered for [{$integration}].");
    }
}
