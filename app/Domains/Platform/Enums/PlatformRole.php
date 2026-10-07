<?php

namespace App\Domains\Platform\Enums;

/**
 * Platform staff roles (PRD §5). Held outside every organization: they are
 * never spatie roles in an organization team, and never come from organization
 * membership or invitations (ADR 0007).
 */
enum PlatformRole: string
{
    /** Provisions and supports organizations. No routine access to guest records. */
    case Administrator = 'platform_administrator';

    /** Monitors ZRA delivery across properties (later milestones). */
    case ComplianceOperator = 'compliance_operator';

    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Platform administrator',
            self::ComplianceOperator => 'Compliance operator',
        };
    }
}
