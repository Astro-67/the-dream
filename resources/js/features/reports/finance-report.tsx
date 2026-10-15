import { Link, usePage } from '@inertiajs/react';
import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
import { Pagination } from '@/components/ui/pagination';
import { StatusChip } from '@/components/ui/status-chip';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate, formatMoney, formatTime } from '@/lib/utils';
import { show } from '@/routes/reservations';
import type { Paginated } from '@/types';

type Amounts = {
    currency: string;
    payments: number;
    refunds: number;
    reversals: number;
    net: number;
};

export type PropertyFinance = {
    folios: Paginated<{
        id: string;
        reservationNumber: string;
        status: string;
        departure: string;
        departed: boolean;
        currency: string;
        balance: number;
    }>;
    openAfterDeparture: { currency: string; folios: number; balance: number }[];
    payments: {
        rows: (Amounts & {
            method: string;
            shiftId: string | null;
            cashier: string | null;
            shiftOpenedAt: string | null;
        })[];
        totals: Amounts[];
    };
};

const methods: Record<string, string> = {
    cash: 'Cash',
    bank_transfer: 'Bank transfer',
    mobile_money: 'Mobile money',
    card: 'Card',
    channel_collected: 'Channel collected',
};

const stays: Record<string, string> = {
    confirmed: 'Not arrived',
    checked_in: 'In house',
    checked_out: 'Departed',
    cancelled: 'Cancelled',
    no_show: 'No-show',
    void: 'Void',
};

/** "7 Oct 2026" for an instant, in the property's timezone. */
const openedOn = (instant: string, timeZone: string) =>
    new Intl.DateTimeFormat('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone,
    }).format(new Date(instant));

function SectionTitle({
    id,
    title,
    children,
}: {
    id: string;
    title: string;
    children: string;
}) {
    return (
        <div>
            <h2 id={id} className="text-lg font-semibold">
                {title}
            </h2>
            <p className="max-w-prose text-sm text-muted-foreground">
                {children}
            </p>
        </div>
    );
}

/** Folio balances now, and money received over the selected business dates. Never adds currencies. */
export default function FinanceReport({
    finance,
}: {
    finance: PropertyFinance;
}) {
    const timezone = usePage().props.tenant.property?.timezone ?? 'UTC';
    const { folios, openAfterDeparture, payments } = finance;

    return (
        <>
            <section aria-labelledby="balances-title" className="grid gap-4">
                <SectionTitle id="balances-title" title="Folio balances">
                    A snapshot at the refresh time, not limited to the selected
                    dates. Charges less payments and credits on each folio with
                    a balance that is not zero. A negative balance is owed to
                    the guest.
                </SectionTitle>
                <div className="grid gap-2">
                    <h3 className="font-medium">
                        Open balances after departure
                    </h3>
                    {openAfterDeparture.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No departed guest has an open balance.
                        </p>
                    ) : (
                        <dl className="flex flex-wrap gap-x-12 gap-y-4 rounded-xl border bg-card p-4">
                            {openAfterDeparture.map((row) => (
                                <div key={row.currency}>
                                    <dt className="text-sm text-muted-foreground">
                                        {row.currency}, {row.folios}{' '}
                                        {row.folios === 1 ? 'folio' : 'folios'}
                                    </dt>
                                    <dd className="text-2xl font-semibold tabular-nums">
                                        {formatMoney(row.balance, row.currency)}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    )}
                </div>
                {folios.data.length === 0 ? (
                    <EmptyState message="Every folio is settled." />
                ) : (
                    <div className="grid gap-2">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Reservation number</TableHead>
                                    <TableHead>Stay</TableHead>
                                    <TableHead>Departure</TableHead>
                                    <TableHead numeric>Balance</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {folios.data.map((folio) => (
                                    <TableRow key={folio.id}>
                                        <TableCell>
                                            <Link
                                                href={show(
                                                    folio.reservationNumber,
                                                )}
                                                className="text-primary underline"
                                            >
                                                <Identifier
                                                    value={
                                                        folio.reservationNumber
                                                    }
                                                />
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            {folio.departed ? (
                                                <StatusChip tone="warning">
                                                    Departed, balance open
                                                </StatusChip>
                                            ) : (
                                                (stays[folio.status] ??
                                                folio.status)
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {formatDate(folio.departure)}
                                        </TableCell>
                                        <TableCell numeric>
                                            {formatMoney(
                                                folio.balance,
                                                folio.currency,
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination page={folios} />
                    </div>
                )}
            </section>
            <section aria-labelledby="payments-title" className="grid gap-4">
                <SectionTitle id="payments-title" title="Payments">
                    Money received on the selected business dates, by method,
                    currency and cashier shift. Refunds are money paid back. A
                    reversal cancels an earlier payment or refund and counts on
                    the business date it was posted. Net is payments less
                    refunds plus reversals. Transfers and write-offs are not
                    payments.
                </SectionTitle>
                {payments.rows.length === 0 ? (
                    <EmptyState message="No payments, refunds or reversals on these dates." />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Method</TableHead>
                                <TableHead>Cashier shift</TableHead>
                                <TableHead>Currency</TableHead>
                                <TableHead numeric>Payments</TableHead>
                                <TableHead numeric>Refunds</TableHead>
                                <TableHead numeric>Reversals</TableHead>
                                <TableHead numeric>Net</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {payments.rows.map((row) => (
                                <TableRow
                                    key={`${row.method}-${row.currency}-${row.shiftId}`}
                                >
                                    <TableCell>
                                        {methods[row.method] ?? row.method}
                                    </TableCell>
                                    <TableCell>
                                        {row.shiftOpenedAt === null
                                            ? 'No shift'
                                            : `${row.cashier}, opened ${openedOn(row.shiftOpenedAt, timezone)} ${formatTime(row.shiftOpenedAt, timezone)}`}
                                    </TableCell>
                                    <TableCell>{row.currency}</TableCell>
                                    <TableCell numeric>
                                        {formatMoney(
                                            row.payments,
                                            row.currency,
                                        )}
                                    </TableCell>
                                    <TableCell numeric>
                                        {formatMoney(row.refunds, row.currency)}
                                    </TableCell>
                                    <TableCell numeric>
                                        {formatMoney(
                                            row.reversals,
                                            row.currency,
                                        )}
                                    </TableCell>
                                    <TableCell numeric>
                                        {formatMoney(row.net, row.currency)}
                                    </TableCell>
                                </TableRow>
                            ))}
                            {payments.totals.map((total) => (
                                <TableRow
                                    key={`total-${total.currency}`}
                                    className="font-medium"
                                >
                                    <TableCell colSpan={2}>
                                        Total in {total.currency}
                                    </TableCell>
                                    <TableCell>{total.currency}</TableCell>
                                    <TableCell numeric>
                                        {formatMoney(
                                            total.payments,
                                            total.currency,
                                        )}
                                    </TableCell>
                                    <TableCell numeric>
                                        {formatMoney(
                                            total.refunds,
                                            total.currency,
                                        )}
                                    </TableCell>
                                    <TableCell numeric>
                                        {formatMoney(
                                            total.reversals,
                                            total.currency,
                                        )}
                                    </TableCell>
                                    <TableCell numeric>
                                        {formatMoney(total.net, total.currency)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </section>
        </>
    );
}
