<?php

namespace App\Domains\Tenancy\Enums;

/**
 * The organization roles of PRD §5. Each is a spatie role in the organization's team.
 *
 * Ticket #7 fills in the permission sets; until then only the owner's and the
 * group operations manager's property management are defined.
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
            default => [],
        };
    }
}
