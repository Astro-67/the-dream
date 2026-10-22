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
            self::FrontDesk => 'Reception',
            self::Finance => 'Finance',
            self::Housekeeping => 'Housekeeping',
            self::Auditor => 'Auditor',
        };
    }

    /** What the role may do, shown when choosing it. Keep in step with permissions(). */
    public function summary(): string
    {
        return match ($this) {
            self::Owner => 'Everything, in every property, including staff, billing settings and the subscription.',
            self::GroupOperationsManager => 'Operations across properties: reservations, rooms, rates, housekeeping, maintenance and reports. Does not take payments or manage staff.',
            self::PropertyManager => 'Runs their properties: reservations, rooms, rates, housekeeping, maintenance, reports and inviting staff. Does not take payments.',
            self::FrontDesk => 'Reservations, check-in and check-out, guest registration, taking payments and reporting maintenance problems in their properties.',
            self::Finance => 'Payments, corrections, refunds, write-offs and reports in their properties.',
            self::Housekeeping => 'Room cleaning status, their assigned rooms and reporting maintenance problems. No guests, payments or reports.',
            self::Auditor => 'Views reservations, finance and reports in their properties without changing anything.',
        };
    }

    public function mayHaveAllProperties(): bool
    {
        return in_array($this, [self::Owner, self::GroupOperationsManager], true);
    }

    public function isPropertyStaff(): bool
    {
        return ! $this->mayHaveAllProperties();
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return P::names(...match ($this) {
            self::Owner => P::cases(),
            self::GroupOperationsManager => [
                P::PropertiesView, P::PropertiesManage, P::PropertiesUpdate,
                P::MembersView, P::InventoryManage, P::RatesManage,
                P::ReservationsView, P::ReservationsManage, P::ReservationsVoid, P::RoomsAssign, P::GuestsView, P::GuestsRegister, P::GuestsMerge,
                P::HousekeepingView, P::HousekeepingManage, P::HousekeepingSupervise,
                P::MaintenanceView, P::MaintenanceReport, P::MaintenanceManage,
                P::FinanceView, P::ReportsView, P::NightAuditView, P::NightAuditClose,
            ],
            self::PropertyManager => [
                P::PropertiesView, P::PropertiesUpdate,
                P::MembersView, P::MembersInvite, P::OutboxRecover, P::InventoryManage, P::RatesManage, P::TaxManage,
                P::ReservationsView, P::ReservationsManage, P::ReservationsVoid, P::RoomsAssign, P::GuestsView, P::GuestsRegister, P::GuestsMerge, P::CheckoutOverrideBalance,
                P::HousekeepingView, P::HousekeepingManage, P::HousekeepingSupervise, P::HousekeepingOverrideReady,
                P::MaintenanceView, P::MaintenanceReport, P::MaintenanceManage,
                P::FiscalExceptionsReview, P::ReportsView,
                P::ChannelsManage, P::ApprovalsManage, P::NightAuditView, P::NightAuditClose,
            ],
            self::FrontDesk => [
                P::ReservationsView, P::ReservationsManage, P::RoomsAssign,
                P::GuestsView, P::GuestsRegister, P::GuestIdentityView,
                P::ChargesPost, P::HousekeepingView, P::NightAuditView,
                P::MaintenanceView, P::MaintenanceReport,
            ],
            self::Finance => [
                P::ReservationsView, P::GuestsView, P::ChargesPost, P::TaxManage,
                P::FinanceView, P::FinanceCorrect, P::PaymentsRefund, P::FoliosWriteOff, P::FiscalExceptionsReview, P::ReportsView, P::NightAuditView,
            ],
            self::Housekeeping => [P::HousekeepingView, P::HousekeepingManage, P::MaintenanceView, P::MaintenanceReport],
            self::Auditor => [
                P::PropertiesView, P::MembersView,
                P::ReservationsView, P::GuestsView, P::HousekeepingView,
                P::FinanceView, P::ReportsView, P::NightAuditView,
            ],
        });
    }
}
