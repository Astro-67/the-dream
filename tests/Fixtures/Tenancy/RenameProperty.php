<?php

namespace Tests\Fixtures\Tenancy;

use App\Domains\Tenancy\Models\Property;
use App\Support\Jobs\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A tenant-scoped job that changes the property it was handed.
 */
final class RenameProperty implements ShouldQueue, TenantAware
{
    use Queueable;

    public function __construct(public Property $property, public string $name) {}

    public function handle(): void
    {
        $this->property->update(['name' => $this->name]);
    }
}
