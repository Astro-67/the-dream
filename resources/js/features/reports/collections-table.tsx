import { EmptyState } from '@/components/ui/empty-state';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { paymentMethodLabel } from '@/features/billing/payment-form';
import { Link } from '@inertiajs/react';
import { Identifier } from '@/components/ui/identifier';
import { Pagination } from '@/components/ui/pagination';
import { StatusChip } from '@/components/ui/status-chip';
import { formatDate } from '@/features/platform/format';
import { formatMoney } from '@/lib/utils';
import { show as showReceipt } from '@/routes/receipts';
import type { Paginated } from '@/types';

type Amounts = {
    currency: string;
    payments: number;
    refunds: number;
    reversals: number;
    net: number;
};
export type Received = {
    rows: (Amounts & { method: string })[];
    totals: Amounts[];
};

export const periodLabels: Record<string, string> = {
    today: 'Today',
    'this-month': 'This month',
    'last-month': 'Last month',
    'this-year': 'This year',
    custom: 'Custom',
};

export function CollectionsTable({ received }: { received: Received }) {
    if (received.rows.length === 0)
        return (
            <EmptyState message="No payments, refunds or reversals on these business dates." />
        );
    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Method</TableHead>
                    <TableHead numeric>Payments</TableHead>
                    <TableHead numeric>Refunds</TableHead>
                    <TableHead numeric>Reversals</TableHead>
                    <TableHead numeric>Net received</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {received.rows.map((row) => (
                    <TableRow key={`${row.method}-${row.currency}`}>
                        <TableCell>{paymentMethodLabel(row.method)}</TableCell>
                        {(
                            ['payments', 'refunds', 'reversals', 'net'] as const
                        ).map((key) => (
                            <TableCell
                                numeric
                                className="whitespace-nowrap"
                                key={key}
                            >
                                {formatMoney(row[key], row.currency)}
                            </TableCell>
                        ))}
                    </TableRow>
                ))}
                {received.totals.map((row) => (
                    <TableRow key={row.currency} className="font-semibold">
                        <TableCell>Total {row.currency}</TableCell>
                        {(
                            ['payments', 'refunds', 'reversals', 'net'] as const
                        ).map((key) => (
                            <TableCell
                                numeric
                                className="whitespace-nowrap"
                                key={key}
                            >
                                {formatMoney(row[key], row.currency)}
                            </TableCell>
                        ))}
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

export type PaymentRow = {
    id: string;
    type: string;
    category: string;
    business_date: string;
    posted_at: string;
    receipt: { id: string; number: string } | null;
    guest: string | null;
    reservation_number: string | null;
    room: string | null;
    method: string;
    reference: string | null;
    amount: number;
    currency: string;
    processed_by: string | null;
};

const kinds: Record<string, string> = {
    deposit: 'Deposit',
    payment: 'Payment',
    refund: 'Refund',
    reversal: 'Reversed payment',
};

/** Each payment on its own row; refunds and reversals read as corrections, not new payments. */
export function PaymentsTable({
    payments,
}: {
    payments: Paginated<PaymentRow>;
}) {
    if (payments.data.length === 0)
        return (
            <>
                <EmptyState message="No individual payments on this page." />
                <Pagination page={payments} />
            </>
        );
    return (
        <>
            <Table aria-label="Individual payments">
                <TableHeader>
                    <TableRow>
                        <TableHead>Date</TableHead>
                        <TableHead>Receipt</TableHead>
                        <TableHead>Guest</TableHead>
                        <TableHead>Reservation</TableHead>
                        <TableHead>Room</TableHead>
                        <TableHead>Method</TableHead>
                        <TableHead>Reference</TableHead>
                        <TableHead numeric>Amount</TableHead>
                        <TableHead>Processed by</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {payments.data.map((row) => {
                        const correction = row.type !== 'payment';

                        return (
                            <TableRow key={row.id}>
                                <TableCell className="whitespace-nowrap">
                                    {formatDate(row.business_date, 'UTC')}
                                </TableCell>
                                <TableCell>
                                    {row.receipt ? (
                                        <Link
                                            href={showReceipt(row.receipt.id)}
                                            className="text-primary underline"
                                        >
                                            <Identifier
                                                value={row.receipt.number}
                                            />
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                    <div>
                                        {correction ? (
                                            <StatusChip tone="warning">
                                                {kinds[row.type] ?? row.type}
                                            </StatusChip>
                                        ) : (
                                            <span className="text-sm text-muted-foreground">
                                                {kinds[row.category] ??
                                                    row.category}
                                            </span>
                                        )}
                                    </div>
                                </TableCell>
                                <TableCell>
                                    {row.guest ?? 'No guest added'}
                                </TableCell>
                                <TableCell>
                                    {row.reservation_number ? (
                                        <Identifier
                                            value={row.reservation_number}
                                        />
                                    ) : (
                                        '—'
                                    )}
                                </TableCell>
                                <TableCell>
                                    {row.room ?? 'Unassigned'}
                                </TableCell>
                                <TableCell>
                                    {paymentMethodLabel(row.method)}
                                </TableCell>
                                <TableCell>
                                    {row.reference ? (
                                        <Identifier value={row.reference} />
                                    ) : (
                                        '—'
                                    )}
                                </TableCell>
                                <TableCell
                                    numeric
                                    className="whitespace-nowrap"
                                >
                                    {formatMoney(row.amount, row.currency)}
                                </TableCell>
                                <TableCell>
                                    {row.processed_by ?? 'Automation'}
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </Table>
            <Pagination page={payments} />
        </>
    );
}
