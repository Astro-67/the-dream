<?php

namespace App\Support\Outbox;

use App\Support\Jobs\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class DrainOutboxMessage implements ShouldQueue, TenantAware
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $messageId, public readonly ?string $dispatchClaimToken = null) {}

    public function handle(DeliveryEngine $engine): void
    {
        $engine->deliver($this->messageId, $this->dispatchClaimToken);
    }
}
