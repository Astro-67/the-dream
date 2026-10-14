import { Form, Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatTime } from '@/lib/utils';

export type ReportFilters = { from: string; to: string };

export default function ReportLayout({
    title,
    filters,
    refreshedAt,
    action,
    children,
}: {
    title: string;
    filters: ReportFilters;
    refreshedAt: string;
    action: string;
    children: ReactNode;
}) {
    const { property } = usePage().props.tenant;
    const timezone = property?.timezone ?? 'UTC';
    const refreshDate = new Intl.DateTimeFormat('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone: timezone,
    }).format(new Date(refreshedAt));

    return (
        <>
            <Head title={title} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Heading
                    title={title}
                    description="Read-only reports for the current property. Dates include both the first and last night."
                />
                <Form
                    action={action}
                    method="get"
                    className="flex flex-wrap items-end gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="from">From business date</Label>
                                <Input
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
                            <Button
                                type="submit"
                                variant="secondary"
                                disabled={processing}
                            >
                                {processing ? 'Refreshing...' : 'View report'}
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
