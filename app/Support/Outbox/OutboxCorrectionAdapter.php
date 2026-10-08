<?php

namespace App\Support\Outbox;

interface OutboxCorrectionAdapter
{
    /**
     * @param  array<string, mixed>  $correction
     * @return array<string, mixed>
     */
    public function correct(OutboxMessage $rejected, array $correction): array;

    /**
     * @param  array<string, mixed>  $correctedRejectedPayload
     * @return array<string, mixed>
     */
    public function rebuildSuccessor(OutboxMessage $successor, array $correctedRejectedPayload): array;

    public function changedPayloadKeyReuseSafe(): bool;
}
