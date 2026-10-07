<?php

namespace Tests\Fixtures\Tenancy;

use App\Domains\Tenancy\Models\Property;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Platform work, like the future ZRA reporting drainer: it reads across
 * organizations through the platform context.
 */
final class CountPropertiesAcrossOrganizations implements ShouldQueue
{
    use Queueable;

    public static ?int $count = null;

    public function handle(): void
    {
        self::$count = Tenancy::within(
            TenantContext::platform('Nightly property count'),
            fn () => Property::query()->count(),
        );
    }
}
