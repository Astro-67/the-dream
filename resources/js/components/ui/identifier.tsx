import { Check, Copy } from 'lucide-react';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';

/** Reservation numbers, channel references, receipt numbers: mono, optionally copyable. */
export function Identifier({
    value,
    copyable = false,
    className,
}: {
    value: string;
    copyable?: boolean;
    className?: string;
}) {
    const [copied, copy] = useClipboard();

    return (
        <span
            data-slot="identifier"
            className={cn('inline-flex min-w-0 items-center gap-1 wrap-anywhere', className)}
        >
            <span className="font-mono">{value}</span>
            {copyable && (
                <button
                    type="button"
                    onClick={() => copy(value)}
                    aria-label={`Copy ${value}`}
                    className="inline-flex size-6 shrink-0 items-center justify-center rounded-sm text-muted-foreground hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none any-pointer-coarse:min-h-11 any-pointer-coarse:min-w-11"
                >
                    {copied === value ? (
                        <Check className="size-3.5" />
                    ) : (
                        <Copy className="size-3.5" />
                    )}
                </button>
            )}
        </span>
    );
}
