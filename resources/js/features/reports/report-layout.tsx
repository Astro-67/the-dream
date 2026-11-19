import { Form, Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDate, formatTime } from '@/lib/utils';

export type ReportFilters = { from: string; to: string };

export default function ReportLayout({
    title,
    filters,
    refreshedAt,
    action,
    description = 'Read-only reports for the current property. Dates include both the first and last night.',
    fields,
    children,
}: {
    title: string;
    filters: ReportFilters;
    refreshedAt: string;
    action: string;
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
                <header className="space-y-2 border-b pb-6">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {title}
                    </h1>
                    <p className="max-w-prose text-sm text-muted-foreground">
                        {description}
                    </p>
                </header>
                <Form
                    action={action}
                    method="get"
                    options={{ preserveState: true }}
                    className="flex min-w-0 flex-wrap items-end gap-4 border-b pb-6 [&>div]:max-w-full [&>div]:min-w-0 [&>fieldset]:min-w-0"
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
                <p className="text-sm text-muted-foreground">
                    Refreshed{' '}
                    <time dateTime={refreshedAt}>
                        {refreshDate} at {formatTime(refreshedAt, timezone)}
                    </time>{' '}
                    ({timezone}). Computed on request.
                </p>
                {children}
            </div>
        </>
    );
}
