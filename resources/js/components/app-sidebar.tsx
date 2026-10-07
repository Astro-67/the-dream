import { usePage } from '@inertiajs/react';
import {
    BookOpen,
    FolderGit2,
    Hotel,
    LayoutGrid,
    MailPlus,
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
import { dashboard } from '@/routes';
import { index as invitations } from '@/routes/invitations';
import { index as properties } from '@/routes/properties';
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

export function AppSidebar() {
    const { permissions } = usePage().props.auth;
    // Hidden from members who can't use them; the server still checks.
    const canInvite =
        permissions.includes('members.manage') ||
        permissions.includes('members.invite');
    const navItems: NavItem[] = [
        ...mainNavItems,
        ...(permissions.includes('properties.manage')
            ? [{ title: 'Properties', href: properties(), icon: Hotel }]
            : []),
        ...(canInvite ? [invitationsNavItem] : []),
    ];

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
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
