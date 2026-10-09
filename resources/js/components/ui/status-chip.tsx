import { cn } from '@/lib/utils';

// Status is always a label plus colour, never colour alone (docs/design.md).
const tones = {
    success: 'bg-status-success-tint text-status-success',
    warning: 'bg-status-warning-tint text-status-warning',
    error: 'bg-status-error-tint text-status-error',
    info: 'bg-status-info-tint text-status-info',
    occupied: 'bg-status-occupied-tint text-status-occupied',
    inspection: 'bg-status-inspection-tint text-status-inspection',
    unavailable: 'bg-status-unavailable-tint text-status-unavailable',
} as const;

export type StatusTone = keyof typeof tones;

export function StatusChip({
    tone,
    children,
    className,
}: {
    tone: StatusTone;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap',
                tones[tone],
                className,
            )}
        >
            <span aria-hidden className="size-1.5 rounded-full bg-current" />
            {children}
        </span>
    );
}

/** A tag or filter: 4px radius; active filters use Primary Tint. */
export function Tag({
    active = false,
    children,
    className,
}: {
    active?: boolean;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-sm px-1.5 py-0.5 text-xs',
                active
                    ? 'bg-primary-tint text-primary'
                    : 'bg-background text-muted-foreground',
                className,
            )}
        >
            {children}
        </span>
    );
}
