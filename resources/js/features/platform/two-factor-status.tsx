import { cn } from '@/lib/utils';

/** Status chip (design.md): a dot and a label, never colour alone. */
export function TwoFactorStatus({ enabled }: { enabled: boolean }) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs whitespace-nowrap',
                enabled
                    ? 'bg-status-success-tint text-status-success'
                    : 'bg-status-warning-tint text-status-warning',
            )}
        >
            <span
                className={cn(
                    'size-1.5 rounded-full',
                    enabled ? 'bg-status-success-dot' : 'bg-status-warning-dot',
                )}
                aria-hidden
            />
            {enabled ? 'On' : 'Not set up'}
        </span>
    );
}
