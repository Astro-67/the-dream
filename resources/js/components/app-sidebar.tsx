import { usePage } from '@inertiajs/react';
import {
    BedDouble,
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
    MailPlus,
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
import { index as invitations } from '@/routes/invitations';
import { index as guests } from '@/routes/guests';
import { index as bookers } from '@/routes/bookers';
import { index as members } from '@/routes/members';
import { index as nightAudit } from '@/routes/night-audit';
import { index as openBalances } from '@/routes/open-balances';
import { index as platformInvoices } from '@/routes/platform/invoices';
import { index as platformOrganizations } from '@/routes/platform/organizations';
import { index as platformPlans } from '@/routes/platform/plans';
import { index as platformStaff } from '@/routes/platform/staff';
import { index as properties } from '@/routes/properties';
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
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

const invitationsNavItem: NavItem = {
    title: 'Invitations',
    href: invitations(),
    icon: MailPlus,
};

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
    const { platformRoles } = usePage().props.auth;
    // Hidden from members who can't use them; the server still checks.
    const can = useCan();
    const navItems: NavItem[] = [
        ...mainNavItems,
        ...(can('approvals.manage')
            ? [{ title: 'Approvals', href: approvals(), icon: ShieldCheck }]
            : []),
        ...(can('properties.view')
            ? [{ title: 'Properties', href: properties(), icon: Hotel }]
            : []),
        ...(can('reservations.view')
            ? [
                  {
                      title: 'Availability',
                      href: availability(),
                      icon: CalendarDays,
                  },
                  {
                      title: 'Reservations',
                      href: reservations(),
                      icon: CalendarDays,
                  },
                  { title: 'Room types', href: roomTypes(), icon: BedDouble },
                  { title: 'Rooms', href: rooms(), icon: DoorOpen },
                  { title: 'Rate plans', href: ratePlans(), icon: Tags },
                  {
                      title: 'Rate calendar',
                      href: rateCalendar(),
                      icon: CalendarDays,
                  },
                  { title: 'Taxes', href: taxRules(), icon: Percent },
              ]
            : []),
        ...(can('finance.view')
            ? [
                  {
                      title: 'Open balances',
                      href: openBalances(),
                      icon: Wallet,
                  },
              ]
            : []),
        ...(can('night_audit.view')
            ? [{ title: 'Night audit', href: nightAudit(), icon: CalendarDays }]
            : []),
        ...(can('charges.post', 'finance.view')
            ? [
                  {
                      title: 'Cashier shifts',
                      href: cashierShifts(),
                      icon: Banknote,
                  },
              ]
            : []),
        ...(can('housekeeping.view')
            ? [{ title: 'Housekeeping', href: housekeeping(), icon: DoorOpen }]
            : []),
        ...(can('housekeeping.manage')
            ? [{ title: 'My rooms', href: myRooms(), icon: DoorOpen }]
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
        ...(can('guests.view')
            ? [
                  { title: 'Guests', href: guests(), icon: Users },
                  { title: 'Bookers', href: bookers(), icon: Users },
              ]
            : []),
        ...(can('members.view')
            ? [{ title: 'Members', href: members(), icon: Users }]
            : []),
        ...(can('members.manage', 'members.invite')
            ? [invitationsNavItem]
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
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarTenant />
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={navItems} />
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
