<?php

namespace App\Support\Audit;

use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;

/**
 * Records a sensitive action in the audit trail (spatie/laravel-activitylog),
 * tagged with the organization and property of the current tenant context
 * (architecture §9). Never pass secrets or identity document data in $properties.
 */
final class Audit
{
    /**
     * @param  array<string, mixed>  $properties  extra detail, such as `attributes` and `old` values; may override the tenant IDs
     */
    public static function record(?User $actor, string $event, Model $subject, string $description, array $properties = []): void
    {
        $context = Tenancy::current();

        activity('audit')
            ->causedBy($actor)
            ->performedOn($subject)
            ->event($event)
            ->withProperties([
                'organization_id' => $context?->organizationId,
                'property_id' => $context?->propertyId,
                'result' => 'success',
                ...$properties,
            ])
            ->log($description);
    }
}
