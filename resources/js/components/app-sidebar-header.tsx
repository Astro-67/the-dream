import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import AppLogo from '@/components/app-logo';
import { AppearanceToggle } from '@/components/appearance-toggle';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { NavUser } from '@/components/nav-user';
import { CurrentProperty } from '@/components/shared/tenant-context';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { preventOfflineWrite, useOnline } from '@/hooks/use-online';
import { dashboard } from '@/routes';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { tenant } = usePage().props;
    const online = useOnline();
    const header = useRef<HTMLElement>(null);

    useEffect(() => {
        const element = header.current;
        if (!element) return;

        const observer = new ResizeObserver(() => {
            document.documentElement.style.setProperty(
                '--workspace-header-height',
                `${element.offsetHeight}px`,
            );
        });
        observer.observe(element);
        return () => {
            observer.disconnect();
            document.documentElement.style.removeProperty(
                '--workspace-header-height',
            );
        };
    }, []);

    useEffect(() => router.on('before', preventOfflineWrite), []);

    return (
        <header
            ref={header}
            className="workspace-context sticky top-0 z-30 shrink-0 border-b bg-background print:hidden"
        >
            <div className="workspace-masthead grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-3 px-4 py-4 md:grid-cols-[minmax(0,1fr)_auto_auto] md:gap-x-6 md:px-6">
                <div className="col-span-2 col-start-1 row-start-1 flex min-w-0 items-center gap-3 pr-14 md:col-span-1 md:pr-0">
                    <SidebarTrigger
                        className="size-11 shrink-0 border border-current/50 md:hidden"
                        aria-label="Open workspace navigation"
                    />
                    <Link
                        href={dashboard()}
                        aria-label="Karafuu home"
                        className="focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                    >
                        <AppLogo
                            inverted
                            className="h-14 object-contain object-left"
                        />
                    </Link>
                </div>
                <div className="col-start-1 row-start-2 min-w-0 text-sm wrap-anywhere md:hidden">
                    {tenant.property?.name ??
                        tenant.organization?.name ??
                        'Account workspace'}
                </div>
                <div className="col-start-2 row-start-2 text-right md:row-start-1">
                    <CurrentProperty />
                    <p className="mt-1 text-xs" role="status">
                        {online ? 'Online' : 'Offline'}
                    </p>
                </div>
                <div className="col-start-2 row-start-1 justify-self-end md:col-start-3">
                    <NavUser compact />
                </div>
            </div>
            {!online && (
                <div
                    role="alert"
                    className="border-b bg-status-warning-tint px-4 py-3 text-sm text-status-warning md:px-6"
                >
                    Offline: changes can&apos;t be saved.
                </div>
            )}
            <div className="flex min-w-0 items-center justify-between gap-3 px-4 py-1 md:px-6">
                <Breadcrumbs breadcrumbs={breadcrumbs} />
                <AppearanceToggle />
            </div>
        </header>
    );
}
