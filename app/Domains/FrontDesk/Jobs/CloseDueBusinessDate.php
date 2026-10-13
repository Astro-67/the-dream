<?php

namespace App\Domains\FrontDesk\Jobs;

use App\Domains\FrontDesk\Actions\CloseBusinessDate;
use App\Support\Jobs\TenantAware;
use App\Support\Tenancy\Tenancy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use LogicException;

/**
 * Automated night audit for the property in the captured tenant context.
 * Carries only the business date due, so it can never name another tenant's property.
 */
final class CloseDueBusinessDate implements ShouldQueue, TenantAware
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public readonly string $businessDate) {}

    public function handle(CloseBusinessDate $close): void
    {
        $close->handle(null, Tenancy::property() ?? throw new LogicException('Night audit requires a property in context.'), $this->businessDate);
    }
}
