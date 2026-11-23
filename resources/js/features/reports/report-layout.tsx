import { Form, Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ChartNoAxesCombined, Clock3 } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn, formatDate, formatTime } from '@/lib/utils';

export type ReportFilters = { from: string; to: string };

export default function ReportLayout({
    title,
    filters,
    refreshedAt,
    action,
    filterStyle = 'panel',
    description = 'Read-only reports for the current property. Dates include both the first and last night.',
    fields,
    children,
}: {
    title: string;
    filters: ReportFilters;
    refreshedAt: string;
    action: string;
    filterStyle?: 'plain' | 'panel';
    description?: string;
    /** Extra filter fields, placed before the submit button. */
    fields?: ReactNode;
    children: ReactNode;
}) {
    const { property } = usePage().props.tenant;
    const timezone = property?.timezone ?? 'UTC';
    const refreshDate = formatDate(refreshedAt, timezone);

    return (
        <>
            <Head title={title} />
            <div className="flex min-w-0 flex-col gap-6 md:gap-8">
                <header className="flex flex-wrap items-start justify-between gap-4 border border-l-4 border-border border-l-primary bg-card p-4 shadow-panel md:p-5">
                    <div className="flex min-w-0 items-start gap-3">
                        <span className="grid size-10 shrink-0 place-items-center border border-primary/15 bg-primary-tint text-primary">
                            <ChartNoAxesCombined
                                aria-hidden="true"
                                className="size-5"
                            />
                        </span>
                        <div className="space-y-1">
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {title}
                            </h1>
                            <p className="max-w-prose text-sm text-muted-foreground">
                                {description}
                            </p>
                        </div>
                    </div>
                    <p className="inline-flex items-center gap-2 border border-border bg-muted/30 px-3 py-2 text-xs text-muted-foreground">
                        <Clock3
                            aria-hidden="true"
                            className="size-4 text-primary"
                        />
                        <span>
                            Refreshed{' '}
                            <time dateTime={refreshedAt}>
                                {refreshDate} at{' '}
                                {formatTime(refreshedAt, timezone)}
                            </time>{' '}
                            ({timezone})
                        </span>
                    </p>
                </header>
                <Form
                    action={action}
                    method="get"
                    options={{ preserveState: true }}
                    className={cn(
                        'flex min-w-0 flex-wrap items-end [&>div]:max-w-full [&>div]:min-w-0 [&>fieldset]:min-w-0',
                        filterStyle === 'panel'
                            ? 'gap-4 border border-border bg-card p-4 shadow-panel md:p-5'
                            : 'gap-4 border-b pb-6',
                    )}
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="from">From business date</Label>
                                <Input
                                    className="min-h-11 md:min-h-9"
                                    id="from"
                                    name="from"
                                    type="date"
                                    defaultValue={filters.from}
                                    required
                                    aria-invalid={!!errors.from}
                                    aria-describedby={
                                        errors.from ? 'from-error' : undefined
                                    }
                                />
                                <InputError
                                    id="from-error"
                                    message={errors.from}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="to">To business date</Label>
                                <Input
                                    className="min-h-11 md:min-h-9"
                                    id="to"
                                    name="to"
                                    type="date"
                                    defaultValue={filters.to}
                                    required
                                    aria-invalid={!!errors.to}
                                    aria-describedby={
                                        errors.to ? 'to-error' : undefined
                                    }
                                />
                                <InputError id="to-error" message={errors.to} />
                            </div>
                            {fields}
                            <Button
                                type="submit"
                                className="min-h-11 md:min-h-9"
                                disabled={processing}
                            >
                                <span className="size-4">
                                    {processing && <Spinner />}
                                </span>
                                View report
                            </Button>
                        </>
                    )}
                </Form>
                <p className="-mt-4 text-xs text-muted-foreground">
                    Report data is computed when you open or refresh this page.
                </p>
                {children}
            </div>
        </>
    );
}
