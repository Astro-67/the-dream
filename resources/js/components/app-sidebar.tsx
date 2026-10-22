import { usePage } from '@inertiajs/react';
import {
    BedDouble,
    ChartNoAxesCombined,
    Banknote,
    CalendarDays,
    CreditCard,
    Building2,
    DoorOpen,
    Hotel,
    LayoutGrid,
    Layers,
    ReceiptText,
    Wallet,
    Percent,
    ShieldCheck,
    Tags,
    Users,
} from 'lucide-react';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { SidebarTenant } from '@/components/shared/tenant-context';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCan } from '@/hooks/use-can';
import { dashboard, rateCalendar } from '@/routes';
import { index as cashierShifts } from '@/routes/cashier-shifts';
import { index as approvals } from '@/routes/approvals';
import { index as guests } from '@/routes/guests';
import { index as members } from '@/routes/members';
import { index as payments } from '@/routes/payments';
import { index as nightAudit } from '@/routes/night-audit';
import { index as openBalances } from '@/routes/open-balances';
import { index as platformInvoices } from '@/routes/platform/invoices';
import { index as platformOrganizations } from '@/routes/platform/organizations';
import { index as platformPlans } from '@/routes/platform/plans';
import { index as platformStaff } from '@/routes/platform/staff';
import { index as properties } from '@/routes/properties';
import { group as groupReports, index as reports } from '@/routes/reports';
import { index as ratePlans } from '@/routes/rate-plans';
import { availability, index as reservations } from '@/routes/reservations';
import { index as roomTypes } from '@/routes/room-types';
import { show as subscription } from '@/routes/subscription';
import { index as taxRules } from '@/routes/tax-rules';
import { index as rooms } from '@/routes/rooms';
import { index as housekeeping, myRooms } from '@/routes/housekeeping';
import { index as assignments } from '@/routes/housekeeping/assignments';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Today',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

const platformNavItems: NavItem[] = [
    {
        title: 'Organizations',
        href: platformOrganizations(),
        icon: Building2,
    },
    {
        title: 'Plans',
        href: platformPlans(),
        icon: Layers,
    },
    {
        title: 'SaaS invoices',
        href: platformInvoices(),
        icon: ReceiptText,
    },
    {
        title: 'Platform staff',
        href: platformStaff(),
        icon: ShieldCheck,
    },
];

export function AppSidebar() {
    const {
        auth: { platformRoles },
        tenant,
    } = usePage().props;
    // Hidden from members who can't use them; the server still checks.
    const can = useCan();
    const frontDeskItems: NavItem[] = [
        ...mainNavItems,
        ...(can('reservations.view')
            ? [
                  // The rate calendar is also the room calendar (arrivals, in-house, overstays).
                  {
                      title: 'Calendar',
                      href: rateCalendar(),
                      icon: CalendarDays,
                  },
                  {
                      title: 'Reservations',
                      href: reservations(),
                      icon: CalendarDays,
                  },
              ]
            : []),
        ...(can('housekeeping.view')
            ? [{ title: 'Housekeeping', href: housekeeping(), icon: DoorOpen }]
            : []),
        ...(can('housekeeping.manage')
            ? [{ title: 'My rooms', href: myRooms(), icon: DoorOpen }]
            : []),
    ];
    const moneyItems: NavItem[] = [
        ...(can('reports.view')
            ? [
                  {
                      title: 'Payments',
                      href: payments(),
                      icon: Banknote,
                  },
              ]
            : []),
        ...(can('charges.post', 'finance.view') &&
        tenant.property?.paymentSettings?.use_cashier_shifts
            ? [
                  {
                      title: 'Cashier shifts',
                      href: cashierShifts(),
                      icon: Banknote,
                  },
              ]
            : []),
        ...(can('finance.view')
            ? [{ title: 'Open balances', href: openBalances(), icon: Wallet }]
            : []),
        ...(can('reports.view')
            ? [{ title: 'Reports', href: reports(), icon: ChartNoAxesCombined }]
            : []),
    ];
    const setupItems: NavItem[] = [
        ...(can('reservations.view')
            ? [
                  { title: 'Rooms', href: rooms(), icon: DoorOpen },
                  { title: 'Room types', href: roomTypes(), icon: BedDouble },
                  { title: 'Rate plans', href: ratePlans(), icon: Tags },
                  { title: 'Taxes', href: taxRules(), icon: Percent },
              ]
            : []),
        ...(can('properties.view')
            ? [{ title: 'Properties', href: properties(), icon: Hotel }]
            : []),
        ...(can('members.view')
            ? [{ title: 'Staff', href: members(), icon: Users }]
            : []),
    ];
    const moreItems: NavItem[] = [
        ...(can('reservations.view')
            ? [
                  {
                      title: 'Availability',
                      href: availability(),
                      icon: CalendarDays,
                  },
              ]
            : []),
        ...(can('night_audit.view')
            ? [{ title: 'Night audit', href: nightAudit(), icon: CalendarDays }]
            : []),
        ...(can('approvals.manage')
            ? [{ title: 'Approvals', href: approvals(), icon: ShieldCheck }]
            : []),
        ...(can('reports.view')
            ? [
                  {
                      title: 'Group reports',
                      href: groupReports(),
                      icon: ChartNoAxesCombined,
                  },
              ]
            : []),
        ...(can('housekeeping.supervise')
            ? [
                  {
                      title: 'Cleaning assignments',
                      href: assignments(),
                      icon: Users,
                  },
              ]
            : []),
        // Bookers are no longer created by staff (ADR 0015); their pages stay reachable from old reservations.
        ...(can('guests.view')
            ? [{ title: 'Guests', href: guests(), icon: Users }]
            : []),
        ...(can('organization.manage')
            ? [
                  {
                      title: 'Subscription',
                      href: subscription(),
                      icon: CreditCard,
                  },
              ]
            : []),
    ];
    const isPlatformAdministrator = platformRoles.includes(
        'platform_administrator',
    );

    return (
        <Sidebar collapsible="icon" variant="sidebar">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarTenant />
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={frontDeskItems} label="Front desk" />
                <NavMain items={moneyItems} label="Money" />
                <NavMain items={setupItems} label="Setup" />
                <NavMain items={moreItems} label="More" collapsible />
                {isPlatformAdministrator && (
                    <NavMain
                        items={platformNavItems}
                        label="Platform administration"
                        labelHref={platformOrganizations()}
                    />
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
