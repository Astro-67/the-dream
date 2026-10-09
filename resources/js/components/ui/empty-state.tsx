import { cn } from '@/lib/utils';

/** One-line explanation plus the next action. No illustrations. */
export function EmptyState({
    message,
    action,
    className,
}: {
    message: string;
    action?: React.ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-col items-center gap-3 px-4 py-10 text-center text-sm text-muted-foreground',
                className,
            )}
        >
            <p>{message}</p>
            {action}
        </div>
    );
}
