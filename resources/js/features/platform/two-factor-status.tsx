import { cn } from '@/lib/utils';

/** Status chip (design.md): a dot and a label, never colour alone. */
export function TwoFactorStatus({ enabled }: { enabled: boolean }) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs whitespace-nowrap',
                enabled
                    ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/15 dark:text-emerald-400'
                    : 'bg-amber-50 text-amber-700 dark:bg-amber-400/15 dark:text-amber-400',
            )}
        >
            <span
                className={cn(
                    'size-1.5 rounded-full',
                    enabled ? 'bg-emerald-500' : 'bg-amber-500',
                )}
                aria-hidden
            />
            {enabled ? 'On' : 'Not set up'}
        </span>
    );
}
