import { Link, usePage } from '@inertiajs/react';
import { Checkbox } from '@/components/ui/checkbox';
import { EmptyState } from '@/components/ui/empty-state';
import { Label } from '@/components/ui/label';
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
import { paymentMethodLabel } from '@/features/billing/payment-form';
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

type FolioBalance = {
    id: string;
    propertyId: string;
    reservationNumber: string;
    status: string;
    departure: string;
    departed: boolean;
    currency: string;
    balance: number;
    guest: string | null;
    room: string | null;
    charges: number;
    netPayments: number;
    notYetCharged: boolean;
};

export type GroupFolios = Paginated<FolioBalance & { propertyName: string }>;

export type PropertyFinance = {
    folios: Paginated<FolioBalance>;
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

const stays: Record<string, string> = {
    confirmed: 'Not arrived',
    checked_in: 'In house',
    checked_out: 'Departed',
    cancelled: 'Cancelled',
    no_show: 'No-show',
    void: 'Void',
};

/** Money stays on one line, so an amount never splits from its currency. */
export const AmountCell = ({
    amount,
    currency,
}: {
    amount: number;
    currency: string;
}) => (
    <TableCell numeric className="whitespace-nowrap">
        {formatMoney(amount, currency)}
    </TableCell>
);

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

/**
 * A folio's state: owed, owed to the guest, or settled. A zero balance with nights
 * still to charge is Not yet charged, never Settled.
 */
function BalanceChip({
    balance,
    notYetCharged,
}: {
    balance: number;
    notYetCharged: boolean;
}) {
    if (balance > 0) return <StatusChip tone="error">Due</StatusChip>;
    if (balance < 0) return <StatusChip tone="info">Credit</StatusChip>;
    return notYetCharged ? (
        <StatusChip tone="warning">Not yet charged</StatusChip>
    ) : (
        <StatusChip tone="success">Settled</StatusChip>
    );
}

/** The "Show settled" switch: zero-balance folios departing in the selected dates join the list. */
export function ShowSettledField({ checked }: { checked: boolean }) {
    return (
        <div className="flex min-h-11 items-center gap-2 md:min-h-9">
            <Checkbox
                id="settled"
                name="settled"
                value="1"
                defaultChecked={checked}
            />
            <Label htmlFor="settled">
                Show settled folios departing in these dates
            </Label>
        </div>
    );
}

/**
 * Unsettled folios, 50 to a page. Group rows name their property and are not linked:
 * a reservation opens only in its own property's context.
 */
export function FolioBalances({
    folios,
}: {
    folios: PropertyFinance['folios'] | GroupFolios;
}) {
    const group = folios.data.some((folio) => 'propertyName' in folio);

    return folios.data.length === 0 ? (
        <EmptyState message="No folios to show. Every folio is settled, or none departs in these dates." />
    ) : (
        <div className="grid gap-2">
            <Table>
                <TableHeader>
                    <TableRow>
                        {group && <TableHead>Property</TableHead>}
                        <TableHead>Guest</TableHead>
                        <TableHead>Reservation</TableHead>
                        <TableHead>Room</TableHead>
                        <TableHead>Stay</TableHead>
                        <TableHead>Departure</TableHead>
                        <TableHead numeric>Posted charges</TableHead>
                        <TableHead numeric>Net payments</TableHead>
                        <TableHead numeric>Balance</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {folios.data.map((folio) => (
                        <TableRow key={folio.id}>
                            {'propertyName' in folio && (
                                <TableCell>{folio.propertyName}</TableCell>
                            )}
                            <TableCell className="font-semibold">
                                {folio.guest ?? 'No guest added'}
                            </TableCell>
                            <TableCell>
                                {'propertyName' in folio ? (
                                    <Identifier
                                        value={folio.reservationNumber}
                                    />
                                ) : (
                                    <Link
                                        href={show(folio.reservationNumber)}
                                        className="text-primary underline"
                                    >
                                        <Identifier
                                            value={folio.reservationNumber}
                                        />
                                    </Link>
                                )}
                            </TableCell>
                            <TableCell>{folio.room ?? 'Unassigned'}</TableCell>
                            <TableCell>
                                {folio.departed && folio.balance !== 0 ? (
                                    <StatusChip tone="warning">
                                        Departed, balance open
                                    </StatusChip>
                                ) : folio.departed ? (
                                    'Departed'
                                ) : (
                                    (stays[folio.status] ?? folio.status)
                                )}
                            </TableCell>
                            <TableCell>{formatDate(folio.departure)}</TableCell>
                            <AmountCell
                                amount={folio.charges}
                                currency={folio.currency}
                            />
                            <AmountCell
                                amount={folio.netPayments}
                                currency={folio.currency}
                            />
                            <TableCell numeric className="whitespace-nowrap">
                                {formatMoney(folio.balance, folio.currency)}
                                <div className="mt-1 flex flex-col items-end gap-1">
                                    <BalanceChip
                                        balance={folio.balance}
                                        notYetCharged={folio.notYetCharged}
                                    />
                                </div>
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
            <Pagination page={folios} />
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
    const hasShifts = payments.rows.some((row) => row.shiftId !== null);

    return (
        <>
            <section aria-labelledby="balances-title" className="grid gap-4">
                <SectionTitle id="balances-title" title="Folio balances">
                    Posted charges and net payments at the refresh time: each
                    folio with a balance that is not zero, whatever its dates.
                    Show settled adds folios whose stay departs in the selected
                    dates. A credit balance is owed to the guest, not a debt.
                    Nights not yet charged are not in these totals.
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
                <FolioBalances folios={folios} />
            </section>
            <section aria-labelledby="payments-title" className="grid gap-4">
                <SectionTitle id="payments-title" title="Payments">
                    Money received on the selected business dates, by method,
                    currency, with cashier shifts where recorded. Refunds are
                    money paid back. A reversal cancels an earlier payment or
                    refund and counts on the business date it was posted. Net is
                    payments less refunds plus reversals. Transfers and
                    write-offs are not payments.
                </SectionTitle>
                {payments.rows.length === 0 ? (
                    <EmptyState message="No payments, refunds or reversals on these dates." />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Method</TableHead>
                                {hasShifts && (
                                    <TableHead>Cashier shift</TableHead>
                                )}
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
                                        {paymentMethodLabel(row.method)}
                                    </TableCell>
                                    {hasShifts && (
                                        <TableCell>
                                            {row.shiftOpenedAt === null
                                                ? 'No shift'
                                                : `${row.cashier}, opened ${formatDate(row.shiftOpenedAt, timezone)} ${formatTime(row.shiftOpenedAt, timezone)}`}
                                        </TableCell>
                                    )}
                                    <TableCell>{row.currency}</TableCell>
                                    <AmountCell
                                        amount={row.payments}
                                        currency={row.currency}
                                    />
                                    <AmountCell
                                        amount={row.refunds}
                                        currency={row.currency}
                                    />
                                    <AmountCell
                                        amount={row.reversals}
                                        currency={row.currency}
                                    />
                                    <AmountCell
                                        amount={row.net}
                                        currency={row.currency}
                                    />
                                </TableRow>
                            ))}
                            {payments.totals.map((total) => (
                                <TableRow
                                    key={`total-${total.currency}`}
                                    className="font-medium"
                                >
                                    <TableCell colSpan={hasShifts ? 2 : 1}>
                                        Total in {total.currency}
                                    </TableCell>
                                    <TableCell>{total.currency}</TableCell>
                                    <AmountCell
                                        amount={total.payments}
                                        currency={total.currency}
                                    />
                                    <AmountCell
                                        amount={total.refunds}
                                        currency={total.currency}
                                    />
                                    <AmountCell
                                        amount={total.reversals}
                                        currency={total.currency}
                                    />
                                    <AmountCell
                                        amount={total.net}
                                        currency={total.currency}
                                    />
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </section>
        </>
    );
}
