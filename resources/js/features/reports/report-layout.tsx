import { Form, Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import Heading from '@/components/heading';
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
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Heading title={title} description={description} />
                <Form
                    action={action}
                    method="get"
                    options={{ preserveState: true }}
                    className="flex flex-wrap items-end gap-4"
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
                                variant="secondary"
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
