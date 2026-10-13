import { Form, Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import { formatMoney } from '@/lib/utils';
import { resolve } from '@/routes/approvals';
import { show as showReservation } from '@/routes/reservations';
import { update } from '@/routes/approval-thresholds';
import type { Paginated } from '@/types';

type Approval = {
    id: string;
    status: string;
    folio_id: string;
    requested_by: string;
    requester: string | null;
    reservation_number: string;
    type: string;
    amount: number;
    currency: string;
    reason: string;
    canResolve: boolean;
};

function Decision({ approval }: { approval: Approval }) {
    return (
        <Form {...resolve.form(approval.id)} className="min-w-64 space-y-2">
            {({ errors, processing }) => (
                <>
                    <PropertyIntent />
                    <Label htmlFor={`reason-${approval.id}`}>
                        Rejection reason
                    </Label>
                    <Input
                        id={`reason-${approval.id}`}
                        name="reason"
                        maxLength={255}
                    />
                    <InputError message={errors.reason ?? errors.approval} />
                    <div className="flex gap-2">
                        <Button
                            size="sm"
                            name="decision"
                            value="approve"
                            disabled={processing || !approval.canResolve}
                        >
                            Approve
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            name="decision"
                            value="reject"
                            disabled={processing || !approval.canResolve}
                        >
                            Reject
                        </Button>
                    </div>
                    {!approval.canResolve && (
                        <p className="text-sm text-muted-foreground">
                            Another approver must decide your request.
                        </p>
                    )}
                </>
            )}
        </Form>
    );
}

export default function Approvals({
    approvals,
    thresholds,
    currencies,
}: {
    approvals: Paginated<Approval>;
    thresholds: { currency: string; amount: number | null }[];
    currencies: string[];
}) {
    return (
        <>
            <Head title="Approvals" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Heading
                    title="Approvals"
                    description="Refunds, reversals and write-offs awaiting a second user. Pending requests do not change folio balances."
                />
                {approvals.data.length === 0 ? (
                    <EmptyState message="No pending approvals. Requests above the property's currency threshold appear here." />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Reservation</TableHead>
                                <TableHead>Request</TableHead>
                                <TableHead numeric>Amount</TableHead>
                                <TableHead>Decision</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {approvals.data.map((approval) => (
                                <TableRow key={approval.id}>
                                    <TableCell>
                                        <Link
                                            href={showReservation(
                                                approval.reservation_number,
                                            )}
                                            className="text-primary underline-offset-4 hover:underline"
                                        >
                                            <Identifier
                                                value={
                                                    approval.reservation_number
                                                }
                                            />
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        <p>{approval.type.replace('_', '-')}</p>
                                        <StatusChip tone="warning">
                                            Pending
                                        </StatusChip>
                                        <p>{approval.reason}</p>
                                        <p className="text-sm text-muted-foreground">
                                            Requested by{' '}
                                            {approval.requester ??
                                                'Former member'}
                                        </p>
                                    </TableCell>
                                    <TableCell numeric>
                                        {formatMoney(
                                            approval.amount,
                                            approval.currency,
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <Decision approval={approval} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
                <Pagination page={approvals} />
                <section className="max-w-[720px] space-y-4">
                    <h2 className="text-lg font-semibold">
                        Approval thresholds
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Amounts are in minor units. Requests above the threshold
                        need approval. Leave the amount empty for none:
                        approvals are switched off for that currency. Zero
                        requires approval for every non-zero adjustment.
                    </p>
                    {currencies.map((currency) => (
                        <Form
                            key={currency}
                            {...update.form()}
                            className="space-y-2"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <PropertyIntent />
                                    <input
                                        type="hidden"
                                        name="currency"
                                        value={currency}
                                    />
                                    <Label htmlFor={`threshold-${currency}`}>
                                        {currency} threshold (none if empty)
                                    </Label>
                                    <div className="flex gap-2">
                                        <Input
                                            id={`threshold-${currency}`}
                                            type="number"
                                            name="amount"
                                            min={0}
                                            max={1000000000000}
                                            defaultValue={
                                                thresholds.find(
                                                    (threshold) =>
                                                        threshold.currency ===
                                                        currency,
                                                )?.amount ?? ''
                                            }
                                        />
                                        <Button
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            Save {currency}
                                        </Button>
                                    </div>
                                    <InputError
                                        message={
                                            errors.amount ?? errors.currency
                                        }
                                    />
                                </>
                            )}
                        </Form>
                    ))}
                </section>
            </div>
        </>
    );
}
