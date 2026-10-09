import * as React from 'react';
import { cn } from '@/lib/utils';

// Data table per docs/design.md: dense 40px rows, sticky header, Border
// dividers, Background row hover, Primary Tint for selected rows
// (data-state="selected"). Use `numeric` on cells holding numbers or money.
// The table scrolls inside its own bordered card, capped below the top bar,
// so its header stays visible however long the list is.

function Table({ className, ...props }: React.ComponentProps<'table'>) {
    return (
        <div
            data-slot="table-container"
            scroll-region=""
            className="relative max-h-[calc(100svh-9rem)] w-full overflow-auto rounded-xl border bg-card"
        >
            <table
                data-slot="table"
                className={cn('w-full caption-bottom text-sm', className)}
                {...props}
            />
        </div>
    );
}

function TableHeader({ className, ...props }: React.ComponentProps<'thead'>) {
    return (
        <thead
            data-slot="table-header"
            className={cn(
                'sticky top-0 z-10 bg-card text-left text-muted-foreground shadow-[inset_0_-1px_0_var(--border)]',
                className,
            )}
            {...props}
        />
    );
}

function TableBody({ className, ...props }: React.ComponentProps<'tbody'>) {
    return (
        <tbody
            data-slot="table-body"
            className={cn('divide-y', className)}
            {...props}
        />
    );
}

function TableRow({ className, ...props }: React.ComponentProps<'tr'>) {
    return (
        <tr
            data-slot="table-row"
            className={cn(
                'h-10 transition-colors hover:bg-background data-[state=selected]:bg-primary-tint pointer-coarse:h-12',
                className,
            )}
            {...props}
        />
    );
}

type CellProps = { numeric?: boolean };

function TableHead({
    className,
    numeric,
    ...props
}: React.ComponentProps<'th'> & CellProps) {
    return (
        <th
            data-slot="table-head"
            className={cn(
                'h-10 px-4 font-medium whitespace-nowrap',
                numeric && 'text-right',
                className,
            )}
            {...props}
        />
    );
}

function TableCell({
    className,
    numeric,
    ...props
}: React.ComponentProps<'td'> & CellProps) {
    return (
        <td
            data-slot="table-cell"
            className={cn(
                'px-4 py-1',
                numeric && 'text-right tabular-nums',
                className,
            )}
            {...props}
        />
    );
}

export { Table, TableHeader, TableBody, TableRow, TableHead, TableCell };
