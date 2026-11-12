import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
import { Pagination } from '@/components/ui/pagination';
import { StatusChip, Tag } from '@/components/ui/status-chip';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ExtraChargeForm } from '@/features/billing/folio-panel';
import type { FolioTotal } from '@/features/billing/folio-summary';
import {
    PaymentForm,
    paymentMethodLabel,
} from '@/features/billing/payment-form';
import {
    GeneralReceiptDialog,
    type GeneralReceiptPreview,
} from '@/features/fiscal-receipting/general-receipt-dialog';
import ZraDocuments, {
    type ZraDocumentsProps,
} from '@/features/fiscal-receipting/zra-documents';
import { formatDate, formatMoney } from '@/lib/utils';
import { index } from '@/routes/customer-accounts';
import { show as showReceipt } from '@/routes/receipts';
import type { Paginated } from '@/types';
import { accountKinds } from './index';

type Item = {
    id: string;
    description: string | null;
    category: string;
    type: string;
    quantity: number;
    unit_price: number;
    discount: number;
    amount: number;
    business_date: string;
    vfms_receipt_number: string | null;
};
type Payment = {
    id: string;
    amount: number;
    method: string;
    business_date: string;
    receipt: { id: string; number: string } | null;
};
type Folio = {
    id: string;
    currency: string;
    summary: { balance: number };
    total: FolioTotal;
    items: Paginated<Item>;
    payments: Paginated<Payment>;
};
type Account = {
    id: string;
    number: string;
    kind: keyof typeof accountKinds;
    customer_name: string;
    status: 'open' | 'closed';
    starts_on: string | null;
    ends_on: string | null;
    note: string | null;
    paying_institution_name: string | null;
};

export default function Show({
    account,
    folios,
    canPost,
    canClose,
    extraCategories,
    acceptedPaymentMethods,
    generalReceipt,
    fiscalEnabled,
    zraDocuments,
}: {
    account: Account;
    folios: Folio[];
    canPost: boolean;
    canClose: boolean;
    extraCategories: string[];
    acceptedPaymentMethods: string[];
    generalReceipt: GeneralReceiptPreview;
    fiscalEnabled: boolean;
    zraDocuments: ZraDocumentsProps;
}) {
    return (
        <>
            <Head title={`${account.number} - ${account.customer_name}`} />
            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-2">
                        <Heading
                            title={account.customer_name}
                            description={account.number}
                        />
                        <div className="flex flex-wrap gap-2">
                            <Tag>{accountKinds[account.kind]}</Tag>
                            <StatusChip
                                tone={
                                    account.status === 'closed'
                                        ? 'success'
                                        : 'info'
                                }
                            >
                                {account.status === 'closed'
                                    ? 'Closed'
                                    : 'Open'}
                            </StatusChip>
                        </div>
                        {account.paying_institution_name && (
                            <p>Paid by {account.paying_institution_name}</p>
                        )}
                        {account.starts_on && (
                            <p className="text-sm text-muted-foreground">
                                {formatDate(account.starts_on, 'UTC')}
                                {account.ends_on &&
                                    ` to ${formatDate(account.ends_on, 'UTC')}`}
                            </p>
                        )}
                        {account.note && (
                            <p className="max-w-prose text-sm text-muted-foreground">
                                {account.note}
                            </p>
                        )}
                    </div>
                    {canClose && (
                        <GeneralReceiptDialog
                            preview={generalReceipt}
                            closeAccount
                            fiscalEnabled={fiscalEnabled}
                        />
                    )}
                </div>
                {folios.map((folio) => (
                    <AccountFolio
                        key={folio.id}
                        folio={folio}
                        canPost={canPost}
                        extraCategories={extraCategories}
                        acceptedPaymentMethods={acceptedPaymentMethods}
                    />
                ))}
                <section className="space-y-4">
                    <h2 className="text-lg font-semibold">ZRA receipt</h2>
                    {!fiscalEnabled ? (
                        <p className="text-sm text-muted-foreground">
                            ZRA filing does not apply to this property.
                        </p>
                    ) : (
                        <ZraDocuments {...zraDocuments} embedded />
                    )}
                </section>
            </div>
        </>
    );
}

function AccountFolio({
    folio,
    canPost,
    extraCategories,
    acceptedPaymentMethods,
}: {
    folio: Folio;
    canPost: boolean;
    extraCategories: string[];
    acceptedPaymentMethods: string[];
}) {
    const [itemOpen, setItemOpen] = useState(false);
    const [paymentOpen, setPaymentOpen] = useState(false);
    return (
        <section className="space-y-4">
            <h2 className="text-lg font-semibold">
                {folio.currency} balance:{' '}
                <span className="tabular-nums">
                    {formatMoney(folio.summary.balance, folio.currency)}
                </span>
            </h2>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h3 className="font-medium">Items</h3>
                {canPost && (
                    <Dialog open={itemOpen} onOpenChange={setItemOpen}>
                        <DialogTrigger asChild>
                            <Button variant="outline">Add item</Button>
                        </DialogTrigger>
                        <DialogContent className="max-h-[90dvh] overflow-y-auto">
                            <DialogHeader>
                                <DialogTitle>Add item</DialogTitle>
                                <DialogDescription>
                                    Items are held for the account's general
                                    receipt.
                                </DialogDescription>
                            </DialogHeader>
                            <ExtraChargeForm
                                folio={folio}
                                categories={extraCategories}
                                onSuccess={() => setItemOpen(false)}
                            />
                        </DialogContent>
                    </Dialog>
                )}
            </div>
            {folio.items.data.length === 0 ? (
                <EmptyState message="No items yet. Add the customer's sale to this account." />
            ) : (
                <>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Business date</TableHead>
                                <TableHead>Item</TableHead>
                                <TableHead numeric>Qty</TableHead>
                                <TableHead numeric>Price</TableHead>
                                <TableHead numeric>Discount</TableHead>
                                <TableHead numeric>Amount</TableHead>
                                <TableHead>ZRA number</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {folio.items.data.map((item) => (
                                <TableRow key={item.id}>
                                    <TableCell>
                                        {formatDate(item.business_date, 'UTC')}
                                    </TableCell>
                                    <TableCell>
                                        {item.description ?? item.category}
                                        {item.type === 'reversal' &&
                                            ' (reversed)'}
                                    </TableCell>
                                    <TableCell numeric>
                                        {item.quantity}
                                    </TableCell>
                                    <TableCell numeric>
                                        {formatMoney(
                                            item.unit_price,
                                            folio.currency,
                                        )}
                                    </TableCell>
                                    <TableCell numeric>
                                        {formatMoney(
                                            item.discount,
                                            folio.currency,
                                        )}
                                    </TableCell>
                                    <TableCell numeric>
                                        {formatMoney(
                                            item.amount,
                                            folio.currency,
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {item.vfms_receipt_number ? (
                                            <Identifier
                                                value={item.vfms_receipt_number}
                                            />
                                        ) : (
                                            'Not receipted'
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <Pagination page={folio.items} />
                </>
            )}
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h3 className="font-medium">Payments</h3>
                {canPost && (
                    <Dialog open={paymentOpen} onOpenChange={setPaymentOpen}>
                        <DialogTrigger asChild>
                            <Button variant="outline">Take payment</Button>
                        </DialogTrigger>
                        <DialogContent className="max-h-[90dvh] overflow-y-auto">
                            <DialogHeader>
                                <DialogTitle>Take payment</DialogTitle>
                                <DialogDescription>
                                    A non-fiscal receipt records the payment.
                                    Closing issues the ZRA receipt.
                                </DialogDescription>
                            </DialogHeader>
                            <PaymentForm
                                folio={folio}
                                total={folio.total}
                                channelCollectedAmount={null}
                                acceptedMethods={acceptedPaymentMethods}
                                onSuccess={() => setPaymentOpen(false)}
                            />
                        </DialogContent>
                    </Dialog>
                )}
            </div>
            {folio.payments.data.length === 0 ? (
                <EmptyState message="No payments yet. Take payment when the customer settles." />
            ) : (
                <>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Business date</TableHead>
                                <TableHead>Method</TableHead>
                                <TableHead numeric>Amount</TableHead>
                                <TableHead>Non-fiscal receipt</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {folio.payments.data.map((payment) => (
                                <TableRow key={payment.id}>
                                    <TableCell>
                                        {formatDate(
                                            payment.business_date,
                                            'UTC',
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {paymentMethodLabel(payment.method)}
                                    </TableCell>
                                    <TableCell numeric>
                                        {formatMoney(
                                            -payment.amount,
                                            folio.currency,
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {payment.receipt ? (
                                            <Link
                                                href={showReceipt(
                                                    payment.receipt.id,
                                                )}
                                                className="underline underline-offset-4"
                                            >
                                                <Identifier
                                                    value={
                                                        payment.receipt.number
                                                    }
                                                />
                                            </Link>
                                        ) : (
                                            'Not applicable'
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <Pagination page={folio.payments} />
                </>
            )}
        </section>
    );
}

Show.layout = ({ account }: { account: Account }) => ({
    breadcrumbs: [
        { title: 'Day use & events', href: index() },
        { title: account.number, href: '#' },
    ],
});
