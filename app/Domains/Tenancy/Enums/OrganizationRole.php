<?php

namespace App\Domains\Tenancy\Enums;

use App\Domains\Tenancy\Enums\OrganizationPermission as P;

/**
 * The organization roles of PRD §5. Each is a spatie role in the organization's team.
 * What each role may do is permissions(); where, is the member's property
 * membership. App\Support\Tenancy\Access checks both.
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
     * The role's permission set: the one place it is defined. Each organization's
     * roles are seeded from it (ProvisionOrganizationRoles). Job titles and
     * departments grant nothing; only the role does.
     *
     * Financial and security settings are owner-only. A group operations manager
     * gets them only when granted to them individually.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return P::names(...match ($this) {
            self::Owner => P::cases(),
            self::GroupOperationsManager => [
                P::PropertiesView, P::PropertiesManage, P::PropertiesUpdate,
                P::MembersView,
                P::ReservationsView, P::ReservationsManage, P::RoomsAssign, P::GuestsView,
                P::HousekeepingView, P::HousekeepingManage,
                P::FinanceView, P::ReportsView,
            ],
            self::PropertyManager => [
                P::PropertiesView, P::PropertiesUpdate,
                // Invite staff to the properties the manager may act in (see InvitationPolicy).
                P::MembersView, P::MembersInvite,
                P::ReservationsView, P::ReservationsManage, P::RoomsAssign, P::GuestsView,
                P::HousekeepingView, P::HousekeepingManage,
                P::FiscalExceptionsReview, P::ReportsView,
                P::ChannelsManage, P::ApprovalsManage,
            ],
            self::FrontDesk => [
                P::ReservationsView, P::ReservationsManage, P::RoomsAssign,
                P::GuestsView, P::GuestsRegister, P::GuestIdentityView,
                P::ChargesPost,
                P::HousekeepingView,
            ],
            self::Finance => [
                P::ReservationsView, P::GuestsView,
                P::ChargesPost,
                P::FinanceView, P::FinanceCorrect, P::FiscalExceptionsReview, P::ReportsView,
            ],
            // Room status and tasks only: no guest identity documents or financial records.
            self::Housekeeping => [
                P::HousekeepingView, P::HousekeepingManage,
            ],
            // Read-only. No identity documents either.
            self::Auditor => [
                P::PropertiesView, P::MembersView,
                P::ReservationsView, P::GuestsView,
                P::HousekeepingView,
                P::FinanceView, P::ReportsView,
            ],
        });
    }
}
