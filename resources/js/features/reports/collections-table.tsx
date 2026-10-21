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
import { formatMoney } from '@/lib/utils';

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
