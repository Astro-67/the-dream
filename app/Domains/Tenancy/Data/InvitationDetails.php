<?php

namespace App\Domains\Tenancy\Data;

use App\Domains\Tenancy\Enums\OrganizationRole;
use Carbon\CarbonImmutable;

/**
 * The invite form: who is invited, what they will be granted, and when the invitation expires.
 */
final readonly class InvitationDetails
{
    public const DEFAULT_EXPIRY_DAYS = 7;

    public function __construct(
        public string $email,
        public MembershipGrant $grant,
        public CarbonImmutable $expiresAt,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated form input
     */
    public static function fromArray(array $input): self
    {
        $role = OrganizationRole::from(is_string($input['role'] ?? null) ? $input['role'] : '');
        $days = is_numeric($input['expires_in_days'] ?? null) ? (int) $input['expires_in_days'] : self::DEFAULT_EXPIRY_DAYS;
        /** @var list<string> $propertyIds */
        $propertyIds = array_values(array_filter((array) ($input['property_ids'] ?? []), 'is_string'));

        return new self(
            email: mb_strtolower(is_string($input['email'] ?? null) ? $input['email'] : ''),
            grant: filter_var($input['all_properties'] ?? false, FILTER_VALIDATE_BOOLEAN)
                ? MembershipGrant::allProperties($role)
                : MembershipGrant::properties($role, $propertyIds),
            expiresAt: CarbonImmutable::now()->addDays($days),
        );
    }
}
