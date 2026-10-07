<?php

namespace App\Domains\Tenancy\Enums;

/**
 * The organization roles of PRD §5. Each is a spatie role in the organization's team.
 *
 * Ticket #7 fills in the permission sets; until then only the owner's and the
 * group operations manager's property management, and the property manager's
 * invitation permission are defined.
 */
enum OrganizationRole: string
{
    case Owner = 'owner';
    case GroupOperationsManager = 'group_operations_manager';
    case PropertyManager = 'property_manager';
    case FrontDesk = 'front_desk';
    case Finance = 'finance';
    case Housekeeping = 'housekeeping';
    case Auditor = 'auditor';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::GroupOperationsManager => 'Group operations manager',
            self::PropertyManager => 'Property manager',
            self::FrontDesk => 'Front desk',
            self::Finance => 'Finance',
            self::Housekeeping => 'Housekeeping',
            self::Auditor => 'Auditor',
        };
    }

    /**
     * Whether a member with this role may be granted all properties (ADR 0007).
     * Everyone else is limited to the properties listed in their membership.
     */
    public function mayHaveAllProperties(): bool
    {
        return in_array($this, [self::Owner, self::GroupOperationsManager], true);
    }

    /**
     * Roles that work within a property rather than across the organization.
     * Property managers may invite staff in these roles to their own property.
     */
    public function isPropertyStaff(): bool
    {
        return ! $this->mayHaveAllProperties();
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => [
                'organization.manage',
                'properties.manage',
                'members.manage',
            ],
            self::GroupOperationsManager => [
                'properties.manage',
            ],
            // Invite staff to the properties the manager may act in (see InvitationPolicy).
            self::PropertyManager => [
                'members.invite',
            ],
            default => [],
        };
    }
}
