<?php

namespace App\Domains\FiscalReceipting\Jobs;

use App\Domains\FiscalReceipting\Actions\RefreshCorrectionDecisions;
use App\Support\Jobs\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Sends one recorded section 2.13 read for the property in the captured tenant context. Never retried. */
final class ReadCorrectionDecisions implements ShouldQueue, TenantAware
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $readId) {}

    public function handle(): void
    {
        app(RefreshCorrectionDecisions::class)->read($this->readId);
    }
}
