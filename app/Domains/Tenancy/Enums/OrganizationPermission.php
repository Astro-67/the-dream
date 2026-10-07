<?php

namespace App\Domains\Tenancy\Enums;

/**
 * Every permission an organization role can carry (PRD §5). OrganizationRole decides
 * which role holds which; ProvisionOrganizationRoles seeds them into each organization.
 *
 * Names are `<area>.<action>`. View permissions only read; every other permission
 * changes something, so it is never given to the auditor. Areas that later
 * milestones build (reservations, folios, housekeeping) are named here already so
 * the role sets are complete from M1.
 *
 * Check them through App\Support\Tenancy\Access, which also checks the record's property.
 */
enum OrganizationPermission: string
{
    // Organization
    case OrganizationManage = 'organization.manage';
    case FinanceSettingsManage = 'settings.finance.manage';
    case SecuritySettingsManage = 'settings.security.manage';

    // Properties
    case PropertiesView = 'properties.view';
    case PropertiesManage = 'properties.manage';
    case PropertiesUpdate = 'properties.update';
    case PropertiesArchive = 'properties.archive';

    // Members
    case MembersView = 'members.view';
    case MembersInvite = 'members.invite';
    case MembersManage = 'members.manage';

    // Front office
    case ReservationsView = 'reservations.view';
    case ReservationsManage = 'reservations.manage';
    case RoomsAssign = 'rooms.assign';
    case GuestsView = 'guests.view';
    case GuestsRegister = 'guests.register';
    case GuestIdentityView = 'guests.identity.view';
    case ChargesPost = 'charges.post';

    // Housekeeping
    case HousekeepingView = 'housekeeping.view';
    case HousekeepingManage = 'housekeeping.manage';

    // Finance and reports
    case FinanceView = 'finance.view';
    case FinanceCorrect = 'finance.correct';
    case FiscalExceptionsReview = 'fiscal.exceptions.review';
    case ReportsView = 'reports.view';

    // Property operations
    case ChannelsManage = 'channels.manage';
    case ApprovalsManage = 'approvals.manage';

    /**
     * Whether the permission only reads. Everything else changes data.
     */
    public function isReadOnly(): bool
    {
        return str_ends_with($this->value, '.view');
    }

    /**
     * @return list<string>
     */
    public static function names(self ...$permissions): array
    {
        return array_map(fn (self $permission) => $permission->value, $permissions);
    }
}
