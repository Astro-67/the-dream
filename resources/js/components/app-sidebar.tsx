import { usePage } from '@inertiajs/react';
import {
    BookOpen,
    BedDouble,
    Building2,
    DoorOpen,
    FolderGit2,
    Hotel,
    LayoutGrid,
    MailPlus,
    Percent,
    ShieldCheck,
    Tags,
    Users,
} from 'lucide-react';
import { NavFooter } from '@/components/nav-footer';
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
import { dashboard } from '@/routes';
import { index as invitations } from '@/routes/invitations';
import { index as members } from '@/routes/members';
import { index as platformOrganizations } from '@/routes/platform/organizations';
import { index as platformStaff } from '@/routes/platform/staff';
import { index as properties } from '@/routes/properties';
import { index as ratePlans } from '@/routes/rate-plans';
import { index as roomTypes } from '@/routes/room-types';
import { index as taxRules } from '@/routes/tax-rules';
import { index as rooms } from '@/routes/rooms';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
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
        ...(can('properties.view')
            ? [{ title: 'Properties', href: properties(), icon: Hotel }]
            : []),
        ...(can('reservations.view')
            ? [
                  { title: 'Room types', href: roomTypes(), icon: BedDouble },
                  { title: 'Rooms', href: rooms(), icon: DoorOpen },
                  { title: 'Rate plans', href: ratePlans(), icon: Tags },
                  { title: 'Taxes', href: taxRules(), icon: Percent },
              ]
            : []),
        ...(can('members.view')
            ? [{ title: 'Members', href: members(), icon: Users }]
            : []),
        ...(can('members.manage', 'members.invite')
            ? [invitationsNavItem]
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
                    />
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
