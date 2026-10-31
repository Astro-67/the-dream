import { AppearanceToggle } from '@/components/appearance-toggle';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { CurrentProperty } from '@/components/shared/tenant-context';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    return (
        <header className="sticky top-0 z-30 flex min-h-16 shrink-0 flex-wrap items-center gap-2 border-b bg-background/80 px-4 py-2 backdrop-blur supports-[backdrop-filter]:bg-background/70 md:px-6">
            <div className="flex min-w-0 items-center gap-2">
                <SidebarTrigger className="-ml-1 min-h-11 min-w-11" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            <div className="flex w-full min-w-0 items-center gap-2 md:ml-auto md:w-auto">
                <CurrentProperty />
                <AppearanceToggle />
            </div>
        </header>
    );
}
