import { Form, Head, Link, usePage } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Pagination } from '@/components/ui/pagination';
import { Spinner } from '@/components/ui/spinner';
import { StatusChip } from '@/components/ui/status-chip';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate } from '@/features/platform/format';
import { formatTime } from '@/lib/utils';
import { show, store } from '@/routes/night-audit';
import type { Paginated } from '@/types';

type ExceptionItem = {
    id: string;
    kind: 'no_show' | 'departure' | 'cashier_shift';
    reference: string;
};

type Close = {
    id: string;
    business_date: string;
    actor: string;
    completed_at: string | null;
};

export default function Index({
    closes,
    businessDate,
    canClose,
    exceptions = [],
    labels,
}: {
    closes: Paginated<Close>;
    businessDate: string;
    canClose: boolean;
    exceptions?: ExceptionItem[];
    labels: Record<string, string>;
}) {
    const { tenant } = usePage().props;
    const timezone = tenant.property?.timezone ?? 'UTC';

    return (
        <>
            <Head title={labels.title} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header>
                    <h1 className="text-2xl font-semibold">{labels.title}</h1>
                    <p className="text-sm text-muted-foreground">
                        {labels.businessDate}: {formatDate(businessDate, 'UTC')}
                    </p>
                </header>
                {canClose && (
                    <Form {...store.form()} key={businessDate}>
                        {({ processing, errors }) => (
                            <div className="flex flex-col items-start gap-3">
                                <PropertyIntent />
                                <input
                                    type="hidden"
                                    name="business_date"
                                    value={businessDate}
                                />
                                <p className="max-w-prose text-sm text-muted-foreground">
                                    {labels.help}
                                </p>
                                <InputError
                                    message={
                                        errors.business_date ??
                                        errors.reservation ??
                                        errors.property
                                    }
                                />
                                <Button disabled={processing}>
                                    {processing && <Spinner />}
                                    {labels.start}
                                </Button>
                            </div>
                        )}
                    </Form>
                )}
                <section aria-labelledby="exception-list">
                    <h2
                        id="exception-list"
                        className="mb-3 text-lg font-semibold"
                    >
                        {labels.exceptions}
                    </h2>
                    {exceptions.length === 0 ? (
                        <EmptyState message={labels.noExceptions} />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{labels.kind}</TableHead>
                                    <TableHead>{labels.reference}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {exceptions.map((item) => (
                                    <TableRow key={item.id}>
                                        <TableCell>
                                            <StatusChip tone="warning">
                                                {labels[item.kind]}
                                            </StatusChip>
                                        </TableCell>
                                        <TableCell>{item.reference}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </section>
                <section aria-labelledby="close-history">
                    <h2
                        id="close-history"
                        className="mb-3 text-lg font-semibold"
                    >
                        {labels.history}
                    </h2>
                    {closes.data.length === 0 ? (
                        <EmptyState message={labels.empty} />
                    ) : (
                        <>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            {labels.businessDate}
                                        </TableHead>
                                        <TableHead>{labels.actor}</TableHead>
                                        <TableHead>
                                            {labels.completed}
                                        </TableHead>
                                        <TableHead>
                                            <span className="sr-only">
                                                {labels.summary}
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {closes.data.map((close) => (
                                        <TableRow key={close.id}>
                                            <TableCell>
                                                {formatDate(
                                                    close.business_date,
                                                    'UTC',
                                                )}
                                            </TableCell>
                                            <TableCell>{close.actor}</TableCell>
                                            <TableCell>
                                                {close.completed_at && (
                                                    <>
                                                        {formatDate(
                                                            close.completed_at,
                                                            timezone,
                                                        )}{' '}
                                                        {formatTime(
                                                            close.completed_at,
                                                            timezone,
                                                        )}
                                                    </>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <Button variant="link" asChild>
                                                    <Link href={show(close.id)}>
                                                        {labels.summary}
                                                    </Link>
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                            <Pagination page={closes} />
                        </>
                    )}
                </section>
            </div>
        </>
    );
}
