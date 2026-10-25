<?php

namespace App\Domains\FiscalReceipting\Contracts;

use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Support\Outbox\DeliveryResult;

/** Where a VFMS call goes: SandboxVfms answers on the server, HttpVfms posts to ZRA. */
interface VfmsGateway
{
    /** @param array{mode: string, request_type: string, path: string, reservation_id?: string, body: array<string, mixed>} $payload */
    public function send(array $payload, VfmsCredential $credential): DeliveryResult;
}
