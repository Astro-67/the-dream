<?php

namespace App\Support\Tenancy;

use App\Domains\Tenancy\Models\Organization;
use App\Domains\Tenancy\Models\Property;
use InvalidArgumentException;

/**
 * Which tenant code is acting for: an organization and optionally one of its
 * properties, or the platform (across organizations) with a stated reason.
 *
 * Holds IDs only, so it can be stored in the session or carried by a queued job
 * and handed back to Tenancy::within() later.
 */
final readonly class TenantContext
{
    private function __construct(
        public ?string $organizationId,
        public ?string $propertyId,
        public ?string $platformReason,
    ) {}

    public static function forOrganization(Organization|string $organization): self
    {
        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        return new self(self::requireId($id), null, null);
    }

    public static function forProperty(Property $property): self
    {
        return new self(self::requireId($property->organization_id), self::requireId($property->getKey()), null);
    }

    public static function fromIds(string $organizationId, ?string $propertyId = null): self
    {
        return new self(
            self::requireId($organizationId),
            $propertyId === null ? null : self::requireId($propertyId),
            null,
        );
    }

    /**
     * Work across organizations. Entering it is logged with the actor and this reason.
     */
    public static function platform(string $reason): self
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Entering the platform context requires a reason.');
        }

        return new self(null, null, $reason);
    }

    public function isPlatform(): bool
    {
        return $this->platformReason !== null;
    }

    private static function requireId(mixed $id): string
    {
        if (! is_string($id) || $id === '') {
            throw new InvalidArgumentException('A tenant context needs a non-empty organization or property ID.');
        }

        return $id;
    }
}
