<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Contracts\PreArchiveCheck;
use App\Domains\Tenancy\Exceptions\ArchiveRefused;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Support\Facades\DB;

/**
 * Archives a property the organization no longer operates. It leaves the
 * switcher and operational lists; its records and audit history are kept.
 *
 * Refused when it is the organization's last active property, or when any
 * registered pre-archive check (see PreArchiveCheck) fails.
 */
class ArchiveProperty
{
    /**
     * @param  iterable<PreArchiveCheck>  $checks
     */
    public function __construct(#[Tag(PreArchiveCheck::TAG)] private readonly iterable $checks) {}

    /**
     * @throws ArchiveRefused with every reason found
     */
    public function handle(?User $actor, Property $property): Property
    {
        if ($property->archived_at !== null) {
            return $property;
        }

        return DB::transaction(function () use ($actor, $property) {
            // Locks the organization's active properties, so two owners can't archive the last two at once.
            $active = Property::query()->whereNull('archived_at')->lockForUpdate()->pluck('id');

            if ($active->doesntContain(fn (string $id) => $id !== $property->getKey())) {
                throw ArchiveRefused::lastActiveProperty($property);
            }

            $reasons = $this->failedChecks($property);

            if ($reasons !== []) {
                throw new ArchiveRefused($reasons);
            }

            $property->archived_at = now();
            $property->save();

            Audit::record($actor, 'archived', $property, 'Property archived', [
                'property_id' => $property->getKey(),
            ]);

            return $property;
        });
    }

    /**
     * Runs every check in the property's own context, so property-owned records are scoped to it.
     *
     * @return list<string>
     */
    private function failedChecks(Property $property): array
    {
        return Tenancy::within(TenantContext::forProperty($property), function () use ($property) {
            $reasons = [];

            foreach ($this->checks as $check) {
                $reason = $check->blockingReason($property);

                if ($reason !== null) {
                    $reasons[] = $reason;
                }
            }

            return $reasons;
        });
    }
}
