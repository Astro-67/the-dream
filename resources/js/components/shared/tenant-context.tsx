import { Link, usePage } from '@inertiajs/react';
import { Building2, ChevronsUpDown, MapPin } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { PropertySwitcherItems } from '@/components/shared/property-switcher';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarMenuButton } from '@/components/ui/sidebar';
import { useIsMobile } from '@/hooks/use-mobile';
import { dashboard } from '@/routes';
import type { JurisdictionProfile } from '@/types';

const jurisdictionLabels: Record<JurisdictionProfile, string> = {
    zanzibar: 'Zanzibar',
    mainland_tanzania: 'Mainland',
};

/**
 * Top of the sidebar: the current organization and property (design.md,
 * Navigation), opening the organization and property switcher.
 */
export function SidebarTenant() {
    const { tenant } = usePage().props;
    const isMobile = useIsMobile();

    if (!tenant.organization) {
        return (
            <SidebarMenuButton size="lg" asChild>
                <Link href={dashboard()} prefetch>
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
                {/* The organization switcher (#9) goes above the properties, with a separator. */}
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
            className="flex min-w-0 items-center gap-2 text-sm"
            data-test="current-property"
        >
            <MapPin
                className="size-4 shrink-0 text-muted-foreground"
                aria-hidden
            />
            <span className="truncate font-medium">{tenant.property.name}</span>
            <span className="shrink-0 rounded-sm bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">
                {jurisdictionLabels[tenant.property.jurisdictionProfile]}
            </span>
        </div>
    );
}
