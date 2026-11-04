<?php

namespace App\Support\Outbox;

/** An adapter that keeps a read model of accepted messages. Called after acceptance commits; a failure never changes delivery. */
interface AcceptanceProjector
{
    public function projectAccepted(OutboxMessage $message): void;
}
