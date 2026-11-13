import { Link, usePage } from '@inertiajs/react';
import { Building2, CalendarDays, ChevronsUpDown } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { OrganizationSwitcherItems } from '@/components/shared/organization-switcher';
import { PropertySwitcherItems } from '@/components/shared/property-switcher';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarMenuButton, useSidebar } from '@/components/ui/sidebar';
import { StatusChip, Tag } from '@/components/ui/status-chip';
import { useIsMobile } from '@/hooks/use-mobile';
import { formatDate } from '@/lib/utils';
import { dashboard } from '@/routes';

/**
 * Top of the sidebar: the current organization and property (design.md,
 * Navigation), opening the organization and property switcher.
 */
export function SidebarTenant() {
    const { tenant } = usePage().props;
    const isMobile = useIsMobile();
    const { setOpenMobile, navigation } = useSidebar();

    if (!tenant.organization) {
        return (
            <SidebarMenuButton
                size="lg"
                className="h-auto py-3 group-data-[collapsible=icon]:hidden"
                asChild
            >
                <Link
                    href={dashboard()}
                    prefetch
                    onClick={() => setOpenMobile(false)}
                >
                    <AppLogo />
                </Link>
            </SidebarMenuButton>
        );
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <SidebarMenuButton
                    size="lg"
                    tooltip={`${tenant.organization.name} · ${tenant.property?.name ?? 'No property'}`}
                    className="h-auto min-h-12 whitespace-normal data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                    data-test="sidebar-tenant"
                >
                    <div
                        data-slot="tenant-icon"
                        className="flex aspect-square size-8 shrink-0 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground"
                    >
                        <Building2 className="size-4" aria-hidden />
                    </div>
                    <div className="grid min-w-0 flex-1 text-left leading-tight">
                        <span className="text-xs wrap-anywhere opacity-80">
                            {tenant.organization.name}
                        </span>
                        <span className="text-sm font-medium wrap-anywhere">
                            {tenant.property?.name ?? 'No property selected'}
                        </span>
                        {tenant.property && (
                            <Tag className="bg-transparent px-0 text-inherit">
                                {tenant.property.jurisdictionLabel}
                            </Tag>
                        )}
                    </div>
                    <ChevronsUpDown className="ml-auto size-4" aria-hidden />
                </SidebarMenuButton>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                className="w-(--radix-dropdown-menu-trigger-width) min-w-60"
                align="start"
                side={isMobile || navigation === 'menu' ? 'bottom' : 'right'}
                sideOffset={4}
            >
                <OrganizationSwitcherItems />
                <PropertySwitcherItems />
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** Top bar: which property the screen acts on. */
export function CurrentProperty() {
    const { tenant } = usePage().props;

    if (!tenant.property) {
        return null;
    }

    return (
        <div
            className="flex min-w-0 flex-col items-end gap-1 text-xs"
            data-test="current-property"
        >
            <span className="opacity-80">Business date</span>
            <span className="flex flex-wrap items-center justify-end gap-2">
                {tenant.property.demo && (
                    <StatusChip tone="info" className="shrink-0">
                        Demo
                    </StatusChip>
                )}
                <span
                    className="flex shrink-0 items-center gap-1 text-sm tabular-nums"
                    data-test="business-date"
                >
                    <CalendarDays className="size-4" aria-hidden />
                    <span className="sr-only">Business date </span>
                    <time dateTime={tenant.property.businessDate}>
                        {formatDate(tenant.property.businessDate)}
                    </time>
                </span>
            </span>
        </div>
    );
}
