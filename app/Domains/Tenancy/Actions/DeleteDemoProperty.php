<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\PropertyPurge;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deletes a demo property with everything it owns. Real properties are archived instead.
 * The audit history stays.
 */
class DeleteDemoProperty
{
    public function handle(User $actor, Property $property): void
    {
        Tenancy::within(TenantContext::forOrganization($property->organization_id), fn () => Audit::validationRefusal($actor, $property, 'Property deletion refused', fn () => DB::transaction(function () use ($actor, $property) {
            $active = Property::query()->active()->lockForUpdate()->get();
            $locked = Property::query()->whereKey($property->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isDemo()) {
                throw ValidationException::withMessages(['delete' => __('Only a demo property can be deleted. Archive this property instead.')]);
            }
            if ($active->doesntContain(fn (Property $other) => $other->id !== $locked->id)) {
                throw ValidationException::withMessages(['delete' => __('The organization needs another active property before the demo property can be deleted.')]);
            }

            Audit::record($actor, 'deleted', $locked, 'Demo property deleted', ['property_id' => $locked->id, 'old' => $locked->only(['name', 'demo'])]);
            PropertyPurge::purge($locked);
            $locked->delete();
        })));
    }
}
