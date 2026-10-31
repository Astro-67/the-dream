import { Fragment } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { Button } from '@/components/ui/button';
import { useTenantKey } from '@/hooks/use-tenant-key';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    const tenantKey = useTenantKey();
    const parent = breadcrumbs.at(-2);

    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {/* Remount the page on a switch: no list or filter from the previous property survives. */}
                <Fragment key={tenantKey}>
                    {parent && (
                        <nav
                            aria-label="Parent page"
                            className="px-4 pt-4 md:px-6 print:hidden"
                        >
                            <Button
                                variant="ghost"
                                asChild
                                className="-ml-3 min-h-11 max-w-full justify-start text-left wrap-anywhere whitespace-normal"
                            >
                                <Link href={parent.href}>
                                    <ArrowLeft aria-hidden="true" />
                                    Back to {parent.title}
                                </Link>
                            </Button>
                        </nav>
                    )}
                    {children}
                </Fragment>
            </AppContent>
        </AppShell>
    );
}
