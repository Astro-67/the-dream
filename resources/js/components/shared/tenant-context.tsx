import { Link, usePage } from '@inertiajs/react';
import { Building2, CalendarDays, ChevronsUpDown, MapPin } from 'lucide-react';
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
    const { setOpenMobile } = useSidebar();

    if (!tenant.organization) {
        return (
            <SidebarMenuButton size="lg" asChild>
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
                    className="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                    data-test="sidebar-tenant"
                >
                    <div className="flex aspect-square size-8 shrink-0 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
                        <Building2 className="size-4" aria-hidden />
                    </div>
                    <div className="grid min-w-0 flex-1 text-left leading-tight">
                        <span className="truncate text-xs text-muted-foreground">
                            {tenant.organization.name}
                        </span>
                        <span className="truncate text-sm font-medium">
                            {tenant.property?.name ?? 'No property selected'}
                        </span>
                    </div>
                    <ChevronsUpDown className="ml-auto size-4" aria-hidden />
                </SidebarMenuButton>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                className="w-(--radix-dropdown-menu-trigger-width) min-w-60 rounded-lg"
                align="start"
                side={isMobile ? 'bottom' : 'right'}
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
            className="flex min-w-0 flex-1 flex-wrap items-center gap-2 text-sm md:flex-initial md:flex-nowrap"
            data-test="current-property"
        >
            <MapPin
                className="size-4 shrink-0 text-muted-foreground"
                aria-hidden
            />
            <span className="min-w-0 flex-1 truncate font-medium md:flex-initial">
                {tenant.property.name}
            </span>
            <Tag className="shrink-0">{tenant.property.jurisdictionLabel}</Tag>
            {tenant.property.demo && (
                <StatusChip tone="info" className="shrink-0">
                    Demo
                </StatusChip>
            )}
            <span
                className="flex shrink-0 items-center gap-1 text-muted-foreground"
                data-test="business-date"
                title="Business date"
            >
                <CalendarDays className="size-4" aria-hidden />
                <span className="sr-only">Business date </span>
                {formatDate(tenant.property.businessDate)}
            </span>
        </div>
    );
}
