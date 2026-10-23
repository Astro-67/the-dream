import { Link } from '@inertiajs/react';
import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
import { Pagination } from '@/components/ui/pagination';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    postingCategories,
    type Posting,
} from '@/features/billing/folio-panel';
import { paymentMethodLabel } from '@/features/billing/payment-form';
import { formatDate } from '@/features/platform/format';
import { formatMoney, formatTime } from '@/lib/utils';
import { show as showReceipt } from '@/routes/receipts';
import type { Paginated } from '@/types';

export type ReceivedPayment = Pick<
    Posting,
    | 'id'
    | 'type'
    | 'category'
    | 'amount'
    | 'method'
    | 'reference'
    | 'actor'
    | 'posted_at'
    | 'receipt'
>;

export function PaymentsReceived({
    payments,
    currency,
    timezone,
}: {
    payments: Paginated<ReceivedPayment>;
    currency: string;
    timezone: string;
}) {
    if (payments.total === 0) return null;

    return (
        <section className="space-y-3">
            {payments.data.length === 0 ? (
                <EmptyState message="No payments on this page. Choose another page." />
            ) : (
                <Table aria-label="Payments received">
                    <TableHeader>
                        <TableRow>
                            <TableHead>Type</TableHead>
                            <TableHead>Method</TableHead>
                            <TableHead numeric>Amount received</TableHead>
                            <TableHead>Time</TableHead>
                            <TableHead>Staff member</TableHead>
                            <TableHead>Reference</TableHead>
                            <TableHead>Receipt</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {payments.data.map((payment) => (
                            <TableRow key={payment.id}>
                                <TableCell>
                                    {/* A reversal keeps the category of the payment it cancels. */}
                                    {payment.type === 'reversal'
                                        ? 'Reversal'
                                        : (postingCategories[
                                              payment.category
                                          ] ?? payment.category)}
                                </TableCell>
                                <TableCell>
                                    {paymentMethodLabel(payment.method ?? '')}
                                </TableCell>
                                <TableCell numeric>
                                    {formatMoney(-payment.amount, currency)}
                                </TableCell>
                                <TableCell>
                                    {formatDate(payment.posted_at, timezone)}{' '}
                                    {formatTime(payment.posted_at, timezone)}
                                </TableCell>
                                <TableCell>{payment.actor ?? '—'}</TableCell>
                                <TableCell>
                                    {payment.reference ? (
                                        <Identifier value={payment.reference} />
                                    ) : (
                                        '—'
                                    )}
                                </TableCell>
                                <TableCell>
                                    {payment.receipt ? (
                                        <Link
                                            href={showReceipt(
                                                payment.receipt.id,
                                            )}
                                            className="text-primary underline underline-offset-4"
                                        >
                                            <Identifier
                                                value={payment.receipt.number}
                                            />
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
            <Pagination page={payments} />
        </section>
    );
}
