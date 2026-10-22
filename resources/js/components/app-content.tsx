import * as React from 'react';
import { SidebarInset } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import type { AppVariant } from '@/types';

type Props = React.ComponentProps<'main'> & {
    variant?: AppVariant;
};

export function AppContent({
    variant = 'sidebar',
    children,
    className,
    ...props
}: Props) {
    if (variant === 'sidebar') {
        return (
            <SidebarInset className={className} {...props}>
                {children}
            </SidebarInset>
        );
    }

    return (
        <main
            className={cn(
                'flex h-full w-full min-w-0 flex-1 flex-col gap-4',
                className,
            )}
            {...props}
        >
            {children}
        </main>
    );
}
