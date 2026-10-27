import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
import { Pagination } from '@/components/ui/pagination';
import { StatusChip, type StatusTone } from '@/components/ui/status-chip';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate, formatMoney } from '@/lib/utils';
import { csv } from '@/routes/reports/filings';
import { show } from '@/routes/reservations';
import type { Paginated } from '@/types';

export type FilingStatus =
    | 'filed'
    | 'refused'
    | 'unknown'
    | 'waiting'
    | 'sandbox'
    | 'closed';

export type ZraFilingsData = {
    counts: { status: FilingStatus; label: string; count: number }[];
    calls: Paginated<{
        id: string;
        date: string;
        reservationNumber: string;
        call: string;
        amount: number | null;
        currency: string | null;
        status: FilingStatus;
        statusLabel: string;
        zraNumber: string | null;
    }>;
};

export const filingTones: Record<FilingStatus, StatusTone> = {
    filed: 'success',
    sandbox: 'info',
    closed: 'unavailable',
    refused: 'error',
    unknown: 'warning',
    waiting: 'info',
};

/** Every VFMS call of the property in the range, with counts by filing status. */
export default function ZraFilings({
    filings,
    range,
}: {
    filings: ZraFilingsData;
    range: { from: string; to: string };
}) {
    return (
        <section aria-labelledby="zra-filings-title" className="grid gap-4">
            <div className="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2
                        id="zra-filings-title"
                        className="text-lg font-semibold"
                    >
                        ZRA filings
                    </h2>
                    <p className="max-w-prose text-sm text-muted-foreground">
                        Every VFMS call for stay events on these business dates.
                        Unknown means ZRA's answer was lost: check the stay
                        before filing again. Waiting calls have not been
                        answered yet.
                    </p>
                </div>
                <Button
                    variant="outline"
                    className="min-h-11 md:min-h-9"
                    asChild
                >
                    <a href={csv.url({ query: range })} download>
                        Download CSV
                    </a>
                </Button>
            </div>
            <dl className="flex flex-wrap gap-x-12 gap-y-4 rounded-xl border bg-card p-4">
                {filings.counts.map((count) => (
                    <div key={count.status}>
                        <dt className="text-sm text-muted-foreground">
                            {count.label}
                        </dt>
                        <dd className="text-2xl font-semibold tabular-nums">
                            {count.count}
                        </dd>
                    </div>
                ))}
            </dl>
            {filings.calls.total > 0 ? (
                <div>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Business date</TableHead>
                                <TableHead>Reservation</TableHead>
                                <TableHead>Call</TableHead>
                                <TableHead numeric>Amount</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>ZRA number</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {filings.calls.data.map((call) => (
                                <TableRow key={call.id}>
                                    <TableCell>
                                        {formatDate(call.date)}
                                    </TableCell>
                                    <TableCell>
                                        <Link
                                            href={show(call.reservationNumber)}
                                            className="text-primary underline"
                                        >
                                            <Identifier
                                                value={call.reservationNumber}
                                            />
                                        </Link>
                                    </TableCell>
                                    <TableCell>{call.call}</TableCell>
                                    <TableCell
                                        numeric
                                        className="whitespace-nowrap"
                                    >
                                        {call.amount === null ||
                                        call.currency === null
                                            ? 'None'
                                            : formatMoney(
                                                  call.amount,
                                                  call.currency,
                                              )}
                                    </TableCell>
                                    <TableCell>
                                        <StatusChip
                                            tone={filingTones[call.status]}
                                        >
                                            {call.statusLabel}
                                        </StatusChip>
                                    </TableCell>
                                    <TableCell>
                                        {call.zraNumber ? (
                                            <Identifier
                                                value={call.zraNumber}
                                                copyable
                                            />
                                        ) : (
                                            <span className="text-muted-foreground">
                                                None yet
                                            </span>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <Pagination page={filings.calls} />
                </div>
            ) : (
                <EmptyState
                    message="No ZRA filings for stay events in this period."
                    action="Change the business date range to review another period."
                />
            )}
        </section>
    );
}
